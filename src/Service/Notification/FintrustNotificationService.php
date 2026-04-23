<?php

namespace App\Service\Notification;

use App\Entity\User\Client\Notification;
use App\Entity\User\User;
use Doctrine\ORM\EntityManagerInterface;

class FintrustNotificationService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function createForUser(User $user, string $title, string $message, string $type = 'INFO', array $metadata = []): Notification
    {
        $notification = (new Notification())
            ->setUser($user)
            ->setType(mb_strtoupper($type))
            ->setMessage($this->buildMessage($title, $message, $metadata))
            ->setIsRead(false)
            ->setCreatedAt(new \DateTimeImmutable());

        $this->entityManager->persist($notification);
        $this->entityManager->flush();

        return $notification;
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function createForAdmins(string $title, string $message, string $type = 'WARNING', array $metadata = []): int
    {
        /** @var User[] $admins */
        $admins = $this->entityManager->getRepository(User::class)->findBy(['role' => User::ROLE_ADMIN]);
        $created = 0;

        foreach ($admins as $admin) {
            $notification = (new Notification())
                ->setUser($admin)
                ->setType(mb_strtoupper($type))
                ->setMessage($this->buildMessage($title, $message, $metadata))
                ->setIsRead(false)
                ->setCreatedAt(new \DateTimeImmutable());

            $this->entityManager->persist($notification);
            $created++;
        }

        if ($created > 0) {
            $this->entityManager->flush();
        }

        return $created;
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function buildMessage(string $title, string $message, array $metadata): string
    {
        $cleanTitle = trim($title) !== '' ? trim($title) : 'Notification FinTrust';
        $cleanMessage = trim($message);
        $parts = [$cleanTitle, $cleanMessage];

        if (isset($metadata['wallet_id'])) {
            $parts[] = 'Wallet #' . (int) $metadata['wallet_id'];
        }

        if (isset($metadata['reference'])) {
            $parts[] = 'Ref: ' . trim((string) $metadata['reference']);
        }

        return implode(' | ', array_filter($parts, static fn (string $part): bool => trim($part) !== ''));
    }
}
