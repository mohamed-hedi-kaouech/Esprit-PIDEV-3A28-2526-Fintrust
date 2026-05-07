<?php

namespace App\Service\Notification;

use App\Entity\User\Client\Notification;
use App\Entity\User\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;

class NotificationQueryService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly NotificationFormatter $formatter,
    ) {
    }

    /**
     * @param array<string, mixed> $filters
     * @return Notification[]
     */
    public function findForUser(User $user, array $filters = [], int $limit = 100): array
    {
        return $this->applyFilters(
            $this->baseQuery()
                ->andWhere('n.user = :user')
                ->setParameter('user', $user),
            $filters
        )
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * @param array<string, mixed> $filters
     * @return Notification[]
     */
    public function findForAdmin(array $filters = [], int $limit = 150): array
    {
        $qb = $this->baseQuery();
        $category = (string) ($filters['category'] ?? 'admin');

        if ($category === 'client') {
            $qb->andWhere('u.role = :clientRole')->setParameter('clientRole', User::ROLE_CLIENT);
        } elseif ($category === 'all') {
            // No category restriction.
        } else {
            $qb->andWhere('u.role = :adminRole')->setParameter('adminRole', User::ROLE_ADMIN);
        }

        return $this->applyFilters($qb, $filters)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * @param array<string, mixed> $filters
     */
    public function countUnreadForUser(User $user, array $filters = []): int
    {
        return (int) $this->applyFilters(
            $this->baseQuery()
                ->select('COUNT(n.id)')
                ->andWhere('n.user = :user')
                ->andWhere('n.isRead = false')
                ->setParameter('user', $user),
            $filters
        )->getQuery()->getSingleScalarResult();
    }

    /**
     * @param array<string, mixed> $filters
     */
    public function countUnreadForAdmin(array $filters = []): int
    {
        $qb = $this->baseQuery()
            ->select('COUNT(n.id)')
            ->andWhere('n.isRead = false');

        $category = (string) ($filters['category'] ?? 'admin');
        if ($category === 'client') {
            $qb->andWhere('u.role = :clientRole')->setParameter('clientRole', User::ROLE_CLIENT);
        } elseif ($category !== 'all') {
            $qb->andWhere('u.role = :adminRole')->setParameter('adminRole', User::ROLE_ADMIN);
        }

        return (int) $this->applyFilters($qb, $filters)->getQuery()->getSingleScalarResult();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    /**
     * @param Notification[] $notifications
     * @return array<int, array<string, mixed>>
     */
    public function serializeList(array $notifications): array
    {
        return array_map(fn (Notification $notification): array => $this->formatter->toArray($notification), $notifications);
    }

    public function markAsRead(Notification $notification): bool
    {
        if ($notification->isRead()) {
            return false;
        }

        $notification->setIsRead(true);
        $this->entityManager->flush();

        return true;
    }

    /**
     * @param Notification[] $notifications
     */
    public function markAllAsRead(array $notifications): int
    {
        $updated = 0;
        foreach ($notifications as $notification) {
            if (!$notification->isRead()) {
                $notification->setIsRead(true);
                $updated++;
            }
        }

        if ($updated > 0) {
            $this->entityManager->flush();
        }

        return $updated;
    }

    public function find(int $id): ?Notification
    {
        /** @var Notification|null $notification */
        $notification = $this->entityManager->getRepository(Notification::class)->find($id);

        return $notification;
    }

    private function baseQuery(): QueryBuilder
    {
        return $this->entityManager->getRepository(Notification::class)
            ->createQueryBuilder('n')
            ->join('n.user', 'u')
            ->addSelect('u')
            ->orderBy('n.createdAt', 'DESC');
    }

    /**
     * @param array<string, mixed> $filters
     */
    private function applyFilters(QueryBuilder $qb, array $filters): QueryBuilder
    {
        $type = mb_strtoupper(trim((string) ($filters['type'] ?? '')));
        if ($type !== '') {
            $qb->andWhere('n.type = :type')->setParameter('type', $type);
        }

        $severity = mb_strtolower(trim((string) ($filters['severity'] ?? '')));
        if ($severity !== '') {
            $mappedType = $this->severityToType($severity);
            if ($mappedType !== null) {
                $qb->andWhere('n.type = :severityType')->setParameter('severityType', $mappedType);
            }
        }

        if (array_key_exists('read', $filters) && $filters['read'] !== '' && $filters['read'] !== null) {
            $qb->andWhere('n.isRead = :read')->setParameter('read', filter_var($filters['read'], FILTER_VALIDATE_BOOLEAN));
        }

        $dateFrom = trim((string) ($filters['date_from'] ?? ''));
        if ($dateFrom !== '') {
            $qb->andWhere('n.createdAt >= :dateFrom')->setParameter('dateFrom', new \DateTimeImmutable($dateFrom));
        }

        $dateTo = trim((string) ($filters['date_to'] ?? ''));
        if ($dateTo !== '') {
            $qb->andWhere('n.createdAt <= :dateTo')->setParameter('dateTo', new \DateTimeImmutable($dateTo . ' 23:59:59'));
        }

        $wallet = trim((string) ($filters['wallet'] ?? ''));
        if ($wallet !== '') {
            $qb->andWhere('LOWER(n.message) LIKE :wallet')->setParameter('wallet', '%wallet%' . mb_strtolower($wallet) . '%');
        }

        return $qb;
    }

    private function severityToType(string $severity): ?string
    {
        return match ($severity) {
            'critical', 'error', 'danger' => 'ERROR',
            'warning', 'high', 'medium' => 'WARNING',
            'success' => 'SUCCESS',
            'info', 'low' => 'INFO',
            default => null,
        };
    }
}
