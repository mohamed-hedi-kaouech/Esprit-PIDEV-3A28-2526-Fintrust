<?php

namespace App\Repository;

use App\Entity\User\Client\PasswordResetRequest;
use App\Entity\User\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PasswordResetRequest>
 */
class PasswordResetRequestRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PasswordResetRequest::class);
    }

    public function countCreatedSinceForRecoveryHash(string $recoveryHash, \DateTimeInterface $since): int
    {
        return (int) $this->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->andWhere('r.recoveryHash = :recoveryHash')
            ->andWhere('r.createdAt >= :since')
            ->setParameter('recoveryHash', $recoveryHash)
            ->setParameter('since', $since)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countCreatedSinceForIp(string $requestIp, \DateTimeInterface $since): int
    {
        return (int) $this->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->andWhere('r.requestIp = :requestIp')
            ->andWhere('r.createdAt >= :since')
            ->setParameter('requestIp', $requestIp)
            ->setParameter('since', $since)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @return PasswordResetRequest[]
     */
    public function findPendingByUser(User $user): array
    {
        return $this->createQueryBuilder('r')
            ->andWhere('r.user = :user')
            ->andWhere('r.status IN (:statuses)')
            ->setParameter('user', $user)
            ->setParameter('statuses', [
                PasswordResetRequest::STATUS_PENDING,
                PasswordResetRequest::STATUS_VERIFIED,
            ])
            ->getQuery()
            ->getResult();
    }

    public function findOneByPublicId(string $publicId): ?PasswordResetRequest
    {
        return $this->createQueryBuilder('r')
            ->andWhere('r.publicId = :publicId')
            ->setParameter('publicId', $publicId)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
