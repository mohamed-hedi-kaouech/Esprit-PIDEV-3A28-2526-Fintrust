<?php

namespace App\Service;

use App\Dto\WalletTransferData;
use App\Entity\User\Client\Notification;
use App\Entity\User\User;
use App\Entity\Wallet\Wallet;
use App\Exception\WalletTransferException;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

class WalletTransferService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly Connection $connection,
        private readonly NotificationService $notificationService,
        private readonly WalletAuditService $walletAuditService,
    ) {
    }

    /**
     * @return array{id:int,reference:string,status:string,source_wallet_id:int,destination_wallet_id:int,amount:float,label:?string,created_at:\DateTimeImmutable,destination_wallet_label:string}
     *
     * @throws WalletTransferException
     */
    public function transfer(User $user, WalletTransferData $data): array
    {
        $sourceWallet = $this->findWalletForUser($user);

        if (!$sourceWallet instanceof Wallet) {
            throw new WalletTransferException('Le wallet source n existe pas.');
        }

        if (!$sourceWallet->getEstActif() || $sourceWallet->getEstBloque()) {
            throw new WalletTransferException('Le wallet source est inactif ou bloque.');
        }

        $amount = round((float) ($data->getAmount() ?? 0), 2);
        if ($amount <= 0) {
            throw new WalletTransferException('Le montant est invalide. Il doit etre strictement superieur a zero.');
        }

        $recipient = trim((string) $data->getRecipient());
        $destinationWallet = $this->findDestinationWallet($recipient, true);

        if (!$destinationWallet instanceof Wallet) {
            throw new WalletTransferException('Le wallet destination n existe pas.');
        }

        if (!$destinationWallet->getEstActif() || $destinationWallet->getEstBloque()) {
            throw new WalletTransferException('Le wallet destination est inactif ou bloque.');
        }

        if (
            $sourceWallet->getIdWallet() === $destinationWallet->getIdWallet()
            || (
                $sourceWallet->getIdUser() !== null
                && $destinationWallet->getIdUser() !== null
                && $sourceWallet->getIdUser() === $destinationWallet->getIdUser()
            )
        ) {
            throw new WalletTransferException('Vous ne pouvez pas transferer de l argent vers vous-meme.');
        }

        $sourceBalance = round((float) $sourceWallet->getSolde(), 2);
        if ($sourceBalance < $amount) {
            throw new WalletTransferException('Solde insuffisant pour effectuer ce transfert.');
        }

        $destinationBalance = round((float) $destinationWallet->getSolde(), 2);
        $label = trim((string) $data->getLabel());
        $createdAt = new \DateTimeImmutable();
        $reference = $this->generateTransferReference();
        $status = 'VALIDE';
        $destinationUser = $this->resolveUserForWallet($destinationWallet);
        $senderDisplayName = $this->resolveSenderDisplayName($sourceWallet, $user);

        $this->connection->beginTransaction();

        try {
            $this->connection->executeStatement(
                'UPDATE wallet SET solde = :solde WHERE id_wallet = :walletId',
                [
                    'solde' => number_format($sourceBalance - $amount, 2, '.', ''),
                    'walletId' => $sourceWallet->getIdWallet(),
                ]
            );

            $this->connection->executeStatement(
                'UPDATE wallet SET solde = :solde WHERE id_wallet = :walletId',
                [
                    'solde' => number_format($destinationBalance + $amount, 2, '.', ''),
                    'walletId' => $destinationWallet->getIdWallet(),
                ]
            );

            $this->connection->executeStatement(
                'INSERT INTO `transaction` (montant, type, description, date_transaction, id_wallet)
                 VALUES (:montant, :type, :description, :dateTransaction, :walletId)',
                [
                    'montant' => $amount,
                    'type' => 'transfert',
                    'description' => $this->buildTransferDescription($reference, $status, $destinationWallet, $label),
                    'dateTransaction' => $createdAt->format('Y-m-d H:i:s'),
                    'walletId' => $sourceWallet->getIdWallet(),
                ]
            );

            $transactionId = (int) $this->connection->lastInsertId();
            $this->connection->commit();

            $sourceWallet->setSolde(number_format($sourceBalance - $amount, 2, '.', ''));
            $destinationWallet->setSolde(number_format($destinationBalance + $amount, 2, '.', ''));

            $this->notificationService->notifyTransferSent(
                $user,
                $reference,
                $amount,
                $sourceWallet->getDevise(),
                $this->formatWalletLabel($destinationWallet),
                $label !== '' ? $label : null
            );

            if ($destinationUser instanceof User) {
                $this->notificationService->notifyTransferReceived(
                    $destinationUser,
                    $reference,
                    $amount,
                    $destinationWallet->getDevise(),
                    $senderDisplayName,
                    $label !== '' ? $label : null
                );
            }

            $transfer = [
                'id' => $transactionId,
                'reference' => $reference,
                'status' => $status,
                'source_wallet_id' => $sourceWallet->getIdWallet(),
                'destination_wallet_id' => $destinationWallet->getIdWallet(),
                'amount' => $amount,
                'label' => $label !== '' ? $label : null,
                'created_at' => $createdAt,
                'destination_wallet_label' => $this->formatWalletLabel($destinationWallet),
            ];

            $this->walletAuditService->logTransfer($transfer, $user, $destinationUser);

            return $transfer;
        } catch (\Throwable $throwable) {
            if ($this->connection->isTransactionActive()) {
                $this->connection->rollBack();
            }

            throw new WalletTransferException(
                'Le transfert a echoue: ' . $throwable->getMessage(),
                previous: $throwable
            );
        }
    }

    public function findWalletForUser(User $user): ?Wallet
    {
        /** @var Wallet|null $wallet */
        $wallet = $this->entityManager->getRepository(Wallet::class)
            ->createQueryBuilder('w')
            ->andWhere('w.user = :user OR w.idUser = :userId')
            ->setParameter('user', $user)
            ->setParameter('userId', $user->getId())
            ->orderBy('w.dateCreation', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $wallet;
    }

    public function findDestinationWallet(string $recipient, bool $strict = false): ?Wallet
    {
        $recipient = trim($recipient);

        if ($recipient === '') {
            if ($strict) {
                throw new WalletTransferException('Le destinataire est obligatoire.');
            }

            return null;
        }

        if (str_contains($recipient, '@')) {
            /** @var User|null $user */
            $user = $this->entityManager->getRepository(User::class)
                ->createQueryBuilder('u')
                ->andWhere('LOWER(u.email) = :email')
                ->setParameter('email', mb_strtolower($recipient))
                ->getQuery()
                ->getOneOrNullResult();

            if (!$user instanceof User) {
                if ($strict) {
                    throw new WalletTransferException('Destinataire introuvable pour l email saisi.');
                }

                return null;
            }

            /** @var Wallet|null $wallet */
            $wallet = $this->entityManager->getRepository(Wallet::class)
                ->createQueryBuilder('w')
                ->andWhere('w.user = :user OR w.idUser = :userId OR LOWER(w.email) = :email')
                ->setParameter('user', $user)
                ->setParameter('userId', $user->getId())
                ->setParameter('email', mb_strtolower($recipient))
                ->orderBy('w.dateCreation', 'DESC')
                ->setMaxResults(1)
                ->getQuery()
                ->getOneOrNullResult();

            if (!$wallet instanceof Wallet && $strict) {
                throw new WalletTransferException('Le destinataire existe, mais il n a pas encore de wallet.');
            }

            return $wallet;
        }

        if (ctype_digit($recipient)) {
            /** @var Wallet|null $wallet */
            $wallet = $this->entityManager->getRepository(Wallet::class)
                ->createQueryBuilder('w')
                ->andWhere('w.idWallet = :walletId')
                ->setParameter('walletId', (int) $recipient)
                ->orderBy('w.dateCreation', 'DESC')
                ->setMaxResults(1)
                ->getQuery()
                ->getOneOrNullResult();

            if (!$wallet instanceof Wallet && $strict) {
                throw new WalletTransferException('Destinataire introuvable pour le numero de wallet saisi.');
            }

            return $wallet;
        }

        if ($strict) {
            throw new WalletTransferException('Le destinataire est invalide. Saisissez un email ou un numero de wallet.');
        }

        return null;
    }

    public function formatWalletLabel(Wallet $wallet): string
    {
        $owner = trim((string) $wallet->getNomProprietaire());

        if ($owner !== '') {
            return sprintf('wallet #%d (%s)', $wallet->getIdWallet(), $owner);
        }

        return sprintf('wallet #%d', $wallet->getIdWallet());
    }

    public function buildTransferDescription(string $reference, string $status, Wallet $destinationWallet, string $label = ''): string
    {
        $parts = [
            'Ref: ' . $reference,
            'Statut: ' . $status,
            'Destinataire: ' . $this->formatWalletLabel($destinationWallet),
        ];

        $label = trim($label);
        if ($label !== '') {
            $parts[] = 'Libelle: ' . $label;
        }

        return implode(' | ', $parts);
    }

    /**
     * @return array<string, string|null>
     */
    public function extractTransferMetadata(?string $text): array
    {
        $source = trim((string) $text);
        $metadata = [
            'reference' => null,
            'status' => null,
            'label' => null,
            'counterparty' => null,
        ];

        if ($source === '') {
            return $metadata;
        }

        if (preg_match('/Ref:\s*([A-Z0-9\-]+)/iu', $source, $matches) === 1) {
            $metadata['reference'] = trim($matches[1]);
        }

        if (preg_match('/Statut:\s*([A-Z_]+)/iu', $source, $matches) === 1) {
            $metadata['status'] = trim($matches[1]);
        }

        if (preg_match('/Libelle:\s*([^|]+)/iu', $source, $matches) === 1) {
            $metadata['label'] = trim(rtrim($matches[1], '. '));
        }

        if (preg_match('/Destinataire:\s*([^|]+)/iu', $source, $matches) === 1) {
            $metadata['counterparty'] = trim(rtrim($matches[1], '. '));
        } elseif (preg_match('/de\s+([^|\.]+)/iu', $source, $matches) === 1) {
            $metadata['counterparty'] = trim(rtrim($matches[1], '. '));
        }

        return $metadata;
    }

    /**
     * @param array{direction?:string,query?:string,status?:string,date_from?:string,date_to?:string} $filters
     * @return array<int, array<string, mixed>>
     */
    public function getTransferHistory(Wallet $wallet, User $user, array $filters = []): array
    {
        $sentTransfers = $this->connection->fetchAllAssociative(
            'SELECT
                t.id_transaction,
                t.montant,
                t.type,
                t.description,
                t.date_transaction,
                t.id_wallet
             FROM `transaction` t
             WHERE LOWER(t.type) = :type
               AND t.id_wallet = :walletId
             ORDER BY t.date_transaction DESC',
            [
                'type' => 'transfert',
                'walletId' => $wallet->getIdWallet(),
            ]
        );

        $history = [];

        foreach ($sentTransfers as $transfer) {
            $meta = $this->extractTransferMetadata($transfer['description'] ?? null);

            $history[] = [
                'id' => 'tx-' . $transfer['id_transaction'],
                'native_id' => (int) $transfer['id_transaction'],
                'reference' => $meta['reference'] ?? ('TX-' . $transfer['id_transaction']),
                'status' => $meta['status'] ?? 'VALIDE',
                'direction' => 'sent',
                'amount' => (float) $transfer['montant'],
                'label' => $meta['label'],
                'counterparty' => $meta['counterparty'] ?? 'Destinataire inconnu',
                'description' => (string) ($transfer['description'] ?? ''),
                'date' => new \DateTimeImmutable((string) $transfer['date_transaction']),
                'source' => 'transaction',
            ];
        }

        foreach ($this->getReceivedTransfersFromNotifications($user) as $entry) {
            $history[] = $entry;
        }

        usort($history, static fn (array $left, array $right): int => $right['date'] <=> $left['date']);

        return array_values(array_filter($history, function (array $item) use ($filters): bool {
            $direction = trim((string) ($filters['direction'] ?? 'all'));
            $query = mb_strtolower(trim((string) ($filters['query'] ?? '')));
            $status = mb_strtoupper(trim((string) ($filters['status'] ?? '')));
            $dateFrom = trim((string) ($filters['date_from'] ?? ''));
            $dateTo = trim((string) ($filters['date_to'] ?? ''));

            if ($direction !== '' && $direction !== 'all' && $item['direction'] !== $direction) {
                return false;
            }

            if ($status !== '' && mb_strtoupper((string) $item['status']) !== $status) {
                return false;
            }

            if ($query !== '') {
                $haystack = mb_strtolower(implode(' ', array_filter([
                    (string) $item['reference'],
                    (string) $item['counterparty'],
                    (string) ($item['label'] ?? ''),
                    (string) $item['description'],
                ])));

                if (!str_contains($haystack, $query)) {
                    return false;
                }
            }

            if ($dateFrom !== '') {
                try {
                    $from = new \DateTimeImmutable($dateFrom . ' 00:00:00');
                    if ($item['date'] < $from) {
                        return false;
                    }
                } catch (\Exception) {
                }
            }

            if ($dateTo !== '') {
                try {
                    $to = (new \DateTimeImmutable($dateTo . ' 00:00:00'))->modify('+1 day');
                    if ($item['date'] >= $to) {
                        return false;
                    }
                } catch (\Exception) {
                }
            }

            return true;
        }));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getReceivedTransfersFromNotifications(User $user): array
    {
        /** @var Notification[] $notifications */
        $notifications = $this->entityManager->getRepository(Notification::class)
            ->createQueryBuilder('n')
            ->andWhere('n.user = :user')
            ->andWhere('n.message LIKE :message')
            ->setParameter('user', $user)
            ->setParameter('message', 'Transfert recu |%')
            ->orderBy('n.createdAt', 'DESC')
            ->getQuery()
            ->getResult();

        $history = [];

        foreach ($notifications as $notification) {
            $message = $notification->getMessage();
            $meta = $this->extractTransferMetadata($message);
            preg_match('/recu\s+([0-9]+(?:\.[0-9]+)?)/iu', $message, $amountMatches);

            $history[] = [
                'id' => 'notif-' . $notification->getId(),
                'native_id' => $notification->getId(),
                'reference' => $meta['reference'] ?? ('NOTIF-' . $notification->getId()),
                'status' => $meta['status'] ?? 'VALIDE',
                'direction' => 'received',
                'amount' => isset($amountMatches[1]) ? (float) $amountMatches[1] : 0.0,
                'label' => $meta['label'],
                'counterparty' => $meta['counterparty'] ?? 'Emetteur inconnu',
                'description' => $message,
                'date' => \DateTimeImmutable::createFromInterface($notification->getCreatedAt()),
                'source' => 'notification',
            ];
        }

        return $history;
    }

    private function resolveUserForWallet(Wallet $wallet): ?User
    {
        if ($wallet->getUser() instanceof User) {
            return $wallet->getUser();
        }

        if ($wallet->getIdUser() !== null) {
            /** @var User|null $user */
            $user = $this->entityManager->getRepository(User::class)->find($wallet->getIdUser());

            if ($user instanceof User) {
                return $user;
            }
        }

        $email = trim((string) $wallet->getEmail());
        if ($email === '') {
            return null;
        }

        /** @var User|null $user */
        $user = $this->entityManager->getRepository(User::class)
            ->createQueryBuilder('u')
            ->andWhere('LOWER(u.email) = :email')
            ->setParameter('email', mb_strtolower($email))
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $user;
    }

    private function resolveSenderDisplayName(Wallet $sourceWallet, User $sourceUser): string
    {
        $fullName = trim($sourceUser->getFullName());
        if ($fullName !== '') {
            return $fullName;
        }

        $owner = trim((string) $sourceWallet->getNomProprietaire());
        if ($owner !== '') {
            return $owner;
        }

        $email = trim((string) $sourceWallet->getEmail());
        if ($email !== '') {
            return $email;
        }

        return 'wallet #' . $sourceWallet->getIdWallet();
    }

    private function generateTransferReference(): string
    {
        return sprintf(
            'TRF-%s-%04d',
            (new \DateTimeImmutable())->format('YmdHis'),
            random_int(1000, 9999)
        );
    }
}
