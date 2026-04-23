<?php

namespace App\Service;

use App\Entity\User\User;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

class AccountDeactivationRequestService
{
    private const STATUS_PENDING = 'PENDING';
    private const STATUS_APPROVED = 'APPROVED';
    private const STATUS_REJECTED = 'REJECTED';

    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function submit(User $user, string $reason, string $impact, array $riskSnapshot = []): array
    {
        $reason = trim($reason);
        $impact = trim($impact);

        if ($reason === '') {
            throw new \RuntimeException('La justification de desactivation est obligatoire.');
        }

        $existing = $this->getLatestForUser($user);
        if (($existing['status'] ?? null) === self::STATUS_PENDING) {
            throw new \RuntimeException('Une demande de desactivation est deja en attente de revue.');
        }

        $now = new \DateTimeImmutable();
        $payload = [
            'requestId' => sprintf('deact_%d_%s', $user->getId(), $now->format('YmdHis')),
            'userId' => $user->getId(),
            'userName' => $user->getFullName(),
            'userEmail' => $user->getEmail(),
            'reason' => $reason,
            'impactSummary' => $impact !== '' ? $impact : 'Le client souhaite fermer son acces FinTrust et interrompre son utilisation du compte.',
            'status' => self::STATUS_PENDING,
            'submittedAt' => $now->format(\DateTimeInterface::ATOM),
            'reviewedAt' => null,
            'reviewedById' => null,
            'reviewedByName' => null,
            'adminNote' => null,
            'riskSnapshot' => $riskSnapshot,
        ];

        $this->persist($payload);

        return $payload;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getLatestForUser(User $user): ?array
    {
        $path = $this->buildPath($user->getId());
        if (!is_file($path)) {
            return null;
        }

        $json = file_get_contents($path);
        if (!is_string($json) || $json === '') {
            return null;
        }

        $payload = json_decode($json, true);

        return is_array($payload) ? $payload : null;
    }

    /**
     * @param iterable<User> $users
     * @return array<int, array<string, mixed>>
     */
    public function getPendingForUsers(iterable $users): array
    {
        $items = [];

        foreach ($users as $user) {
            if (!$user instanceof User || $user->isAdmin()) {
                continue;
            }

            $request = $this->getLatestForUser($user);
            if (($request['status'] ?? null) !== self::STATUS_PENDING) {
                continue;
            }

            $items[] = $request;
        }

        usort($items, static function (array $left, array $right): int {
            return strcmp((string) ($right['submittedAt'] ?? ''), (string) ($left['submittedAt'] ?? ''));
        });

        return $items;
    }

    /**
     * @return array<string, mixed>
     */
    public function approve(User $user, User $admin, string $adminNote = ''): array
    {
        $request = $this->getLatestForUser($user);
        if ($request === null || ($request['status'] ?? null) !== self::STATUS_PENDING) {
            throw new \RuntimeException('Aucune demande de desactivation en attente n a ete trouvee pour ce client.');
        }

        $request['status'] = self::STATUS_APPROVED;
        $request['adminNote'] = trim($adminNote) !== '' ? trim($adminNote) : 'Demande approuvee apres revue admin.';
        $request['reviewedAt'] = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);
        $request['reviewedById'] = $admin->getId();
        $request['reviewedByName'] = $admin->getFullName();

        $this->persist($request);

        return $request;
    }

    /**
     * @return array<string, mixed>
     */
    public function reject(User $user, User $admin, string $adminNote): array
    {
        $request = $this->getLatestForUser($user);
        if ($request === null || ($request['status'] ?? null) !== self::STATUS_PENDING) {
            throw new \RuntimeException('Aucune demande de desactivation en attente n a ete trouvee pour ce client.');
        }

        $note = trim($adminNote);
        if ($note === '') {
            throw new \RuntimeException('Une justification admin est requise pour refuser la demande.');
        }

        $request['status'] = self::STATUS_REJECTED;
        $request['adminNote'] = $note;
        $request['reviewedAt'] = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);
        $request['reviewedById'] = $admin->getId();
        $request['reviewedByName'] = $admin->getFullName();

        $this->persist($request);

        return $request;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function persist(array $payload): void
    {
        $directory = $this->getStorageDirectory();
        if (!is_dir($directory) && !@mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new \RuntimeException('Impossible de preparer le stockage des demandes de desactivation.');
        }

        $encoded = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            throw new \RuntimeException('Impossible de serialiser la demande de desactivation.');
        }

        if (@file_put_contents($this->buildPath((int) $payload['userId']), $encoded) === false) {
            throw new \RuntimeException('Impossible d enregistrer la demande de desactivation.');
        }
    }

    private function getStorageDirectory(): string
    {
        return $this->projectDir . '/var/account-deactivation';
    }

    private function buildPath(int $userId): string
    {
        return $this->getStorageDirectory() . '/user_' . $userId . '.json';
    }
}
