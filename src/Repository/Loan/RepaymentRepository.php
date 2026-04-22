<?php

namespace App\Repository\Loan;

use App\Entity\Loan\Repayment;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Repayment>
 */
class RepaymentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Repayment::class);
    }

    public function findAll(): array
    {
        return $this->findBy([], ['loan' => 'ASC', 'month' => 'ASC']);
    }

    public function findByLoanId(int $loanId): array
    {
        return $this->createQueryBuilder('r')
            ->andWhere('r.loan = :loanId')
            ->setParameter('loanId', $loanId)
            ->orderBy('r.month', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findByLoanAndStatus(int $loanId, string $status): array
    {
        return $this->createQueryBuilder('r')
            ->andWhere('r.loan = :loanId')
            ->andWhere('r.status = :status')
            ->setParameter('loanId', $loanId)
            ->setParameter('status', $status)
            ->orderBy('r.month', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function countUnpaidByLoan(int $loanId): int
    {
        return $this->createQueryBuilder('r')
            ->select('COUNT(r)')
            ->andWhere('r.loan = :loanId')
            ->andWhere('r.status = :status')
            ->setParameter('loanId', $loanId)
            ->setParameter('status', 'UNPAID')
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function sumPaidByLoan(int $loanId): float
    {
        $result = $this->createQueryBuilder('r')
            ->select('SUM(r.monthlyPayment)')
            ->andWhere('r.loan = :loanId')
            ->andWhere('r.status = :status')
            ->setParameter('loanId', $loanId)
            ->setParameter('status', 'PAID')
            ->getQuery()
            ->getSingleScalarResult();

        return $result ? (float) $result : 0.0;
    }


        public function findUnpaidByLoan(int $loanId): array
    {
        return $this->createQueryBuilder('r')
            ->join('r.loan', 'l')
            ->where('l.loanId = :loanId')
            ->andWhere('r.status = :status')
            ->setParameter('loanId', $loanId)
            ->setParameter('status', 'UNPAID')
            ->orderBy('r.month', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findNextUnpaid(int $loanId): ?Repayment
    {
        return $this->createQueryBuilder('r')
            ->join('r.loan', 'l')
            ->where('l.loanId = :loanId')
            ->andWhere('r.status = :status')
            ->setParameter('loanId', $loanId)
            ->setParameter('status', 'UNPAID')
            ->orderBy('r.month', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
