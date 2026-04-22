<?php

namespace App\Repository\Loan;

use App\Entity\Loan\Loan;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Loan>
 */
class LoanRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Loan::class);
    }

    /**
     * GET ALL LOANS - simple, no user join
     */
    public function getAllLoans(): array
    {
        return $this->createQueryBuilder('l')
            ->orderBy('l.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Standard findAll - returns all loans
     */
    public function findAll(): array
    {
        return $this->findBy([], ['createdAt' => 'DESC']);
    }

    /**
     * Find by user ID - no user join needed
     */
    public function findByUserId(int $userId): array
    {
        return $this->createQueryBuilder('l')
            ->andWhere('l.user = :userId')
            ->setParameter('userId', $userId)
            ->orderBy('l.loanId', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find by status - no user join
     */
    public function findByStatus(string $status): array
    {
        return $this->createQueryBuilder('l')
            ->andWhere('l.status = :status')
            ->setParameter('status', $status)
            ->orderBy('l.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Count by status
     */
    public function countByStatus(string $status): int
    {
        return (int) $this->createQueryBuilder('l')
            ->select('COUNT(l.loanId)')
            ->andWhere('l.status = :status')
            ->setParameter('status', $status)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Find pending loans - no user join
     */
    public function findPendingLoans(): array
    {
        return $this->findByStatus('PENDING');
    }

    /**
     * Find active loans - no user join
     */
    public function findActiveLoans(): array
    {
        return $this->createQueryBuilder('l')
            ->where('l.status IN (:statuses)')
            ->setParameter('statuses', ['ACTIVE', 'APPROVED'])
            ->orderBy('l.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find loan with repayments only - no user join
     */
    public function findLoanWithRepayments(int $loanId): ?Loan
    {
        return $this->createQueryBuilder('l')
            ->leftJoin('l.repayments', 'r')
            ->addSelect('r')
            ->where('l.loanId = :id')
            ->setParameter('id', $loanId)
            ->orderBy('r.month', 'ASC')
            ->getQuery()
            ->getOneOrNullResult();
    }
}