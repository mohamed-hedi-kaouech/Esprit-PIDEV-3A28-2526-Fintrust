<?php

namespace App\Repository;

use App\Entity\User\Client\PasswordResetAuditLog;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PasswordResetAuditLog>
 */
class PasswordResetAuditLogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PasswordResetAuditLog::class);
    }

    public function countRecentRequestsForRecoveryHash(string $recoveryHash, \DateTimeInterface $since): int
    {
        return (int) $this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->andWhere('a.recoveryHash = :recoveryHash')
            ->andWhere('a.eventType = :eventType')
            ->andWhere('a.createdAt >= :since')
            ->setParameter('recoveryHash', $recoveryHash)
            ->setParameter('eventType', PasswordResetAuditLog::EVENT_REQUEST_RECEIVED)
            ->setParameter('since', $since)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countRecentRequestsForIp(string $requestIp, \DateTimeInterface $since): int
    {
        return (int) $this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->andWhere('a.requestIp = :requestIp')
            ->andWhere('a.eventType = :eventType')
            ->andWhere('a.createdAt >= :since')
            ->setParameter('requestIp', $requestIp)
            ->setParameter('eventType', PasswordResetAuditLog::EVENT_REQUEST_RECEIVED)
            ->setParameter('since', $since)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
