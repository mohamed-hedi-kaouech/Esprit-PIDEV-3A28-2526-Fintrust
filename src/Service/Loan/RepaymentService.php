<?php
// src/Service/RepaymentService.php
namespace App\Service\Loan;

use App\Entity\Loan\Loan;
use App\Entity\Loan\Repayment;
use App\Repository\Loan\RepaymentRepository;
use Doctrine\ORM\EntityManagerInterface;

class RepaymentService
{
    private EntityManagerInterface $em;
    private RepaymentRepository $repaymentRepo;

    public function __construct(
        EntityManagerInterface $em,
        RepaymentRepository $repaymentRepo
    ) {
        $this->em = $em;
        $this->repaymentRepo = $repaymentRepo;
    }

    // ======================
    // MARK AS PAID
    // ======================

    /**
     * Mark repayment as paid with validation
     */
    public function markAsPaid(int $repayId): void
    {
        $repayment = $this->repaymentRepo->find($repayId);

        if (!$repayment) {
            throw new \RuntimeException('Échéance introuvable.');
        }

        // Check if previous months are paid (sequential payment rule)
        if (!$this->canPayRepayment($repayment->getLoan()->getLoanId(), $repayment->getMonth())) {
            throw new \RuntimeException('Previous installments must be paid first.');
        }

        $repayment->setStatus('PAID');
        
        // Update loan remaining principal
        $this->updateRemainingPrincipal(
            $repayment->getLoan()->getLoanId(), 
            (float) $repayment->getCapitalPart()
        );

        $this->em->flush();

        // Check if loan is fully completed
        $this->updateLoanStatusIfCompleted($repayment->getLoan()->getLoanId());
    }

    // ======================
    // UPDATE REMAINING PRINCIPAL
    // ======================
        public function findNextUnpaid(int $loanId): ?Repayment
    {
        return $this->repaymentRepo->findNextUnpaid($loanId);
    }
    /**
     * Update loan remaining principal after payment
     */
    public function updateRemainingPrincipal(int $loanId, float $capitalPart): void
    {
        $loan = $this->em->getRepository(Loan::class)->find($loanId);

        if (!$loan) {
            throw new \RuntimeException('Loan not found');
        }

        $currentRemaining = (float) $loan->getRemainingPrincipal();
        $newRemaining = max(0, $currentRemaining - $capitalPart);

        $loan->setRemainingPrincipal(number_format($newRemaining, 2, '.', ''));

        $this->em->persist($loan);
    }

    // ======================
    // UPDATE LOAN STATUS IF COMPLETED
    // ======================

    /**
     * Check and update loan status to COMPLETED if all repayments are paid
     */
    public function updateLoanStatusIfCompleted(int $loanId): void
    {
        $loan = $this->em->getRepository(Loan::class)->find($loanId);

        if (!$loan) {
            return;
        }

        // Count unpaid repayments using DQL
        $qb = $this->em->createQueryBuilder();
        $unpaidCount = $qb->select('COUNT(r.repayId)')
            ->from(Repayment::class, 'r')
            ->where('r.loan = :loanId')
            ->andWhere('r.status = :status')
            ->setParameter('loanId', $loanId)
            ->setParameter('status', 'UNPAID')
            ->getQuery()
            ->getSingleScalarResult();

        // If no unpaid repayments, mark loan as completed
        if ($unpaidCount == 0) {
            $loan->setStatus('COMPLETED');
            $this->em->flush();
        }
    }

    // ======================
    // VALIDATION
    // ======================

    /**
     * Check if repayment can be paid (all previous months must be paid)
     */
    public function canPayRepayment(int $loanId, int $month): bool
    {
        $qb = $this->em->createQueryBuilder();
        $unpaidPrevious = $qb->select('COUNT(r.repayId)')
            ->from(Repayment::class, 'r')
            ->join('r.loan', 'l')
            ->where('l.loanId = :loanId')
            ->andWhere('r.month < :month')
            ->andWhere('r.status = :status')
            ->setParameter('loanId', $loanId)
            ->setParameter('month', $month)
            ->setParameter('status', 'UNPAID')
            ->getQuery()
            ->getSingleScalarResult();

        return $unpaidPrevious == 0;
    }

    // ======================
    // GETTERS & QUERIES
    // ======================

    /**
     * Get repayment by ID
     */
    public function findById(int $repayId): ?Repayment
    {
        return $this->repaymentRepo->find($repayId);
    }

    /**
     * Get all repayments for a loan
     * @return Repayment[]
     */
    public function findByLoanId(int $loanId): array
    {
        return $this->repaymentRepo->findByLoanId($loanId);
    }

    /**
     * Get repayment history with status filter
     * @return Repayment[]
     */
    public function findByLoanAndStatus(int $loanId, string $status): array
    {
        return $this->repaymentRepo->createQueryBuilder('r')
            ->join('r.loan', 'l')
            ->where('l.loanId = :loanId')
            ->andWhere('r.status = :status')
            ->setParameter('loanId', $loanId)
            ->setParameter('status', $status)
            ->orderBy('r.month', 'ASC')
            ->getQuery()
            ->getResult();
    }


    /**
     * Get repayment statistics for a loan
     */
    public function getRepaymentStats(int $loanId): array
    {
        $qb = $this->em->createQueryBuilder();
        
        $paidCount = $qb->select('COUNT(r.repayId)')
            ->from(Repayment::class, 'r')
            ->join('r.loan', 'l')
            ->where('l.loanId = :loanId')
            ->andWhere('r.status = :status')
            ->setParameter('loanId', $loanId)
            ->setParameter('status', 'PAID')
            ->getQuery()
            ->getSingleScalarResult();

        $qb2 = $this->em->createQueryBuilder();
        $unpaidCount = $qb2->select('COUNT(r.repayId)')
            ->from(Repayment::class, 'r')
            ->join('r.loan', 'l')
            ->where('l.loanId = :loanId')
            ->andWhere('r.status = :status')
            ->setParameter('loanId', $loanId)
            ->setParameter('status', 'UNPAID')
            ->getQuery()
            ->getSingleScalarResult();

        $qb3 = $this->em->createQueryBuilder();
        $totalPaid = $qb3->select('COALESCE(SUM(r.monthlyPayment), 0)')
            ->from(Repayment::class, 'r')
            ->join('r.loan', 'l')
            ->where('l.loanId = :loanId')
            ->andWhere('r.status = :status')
            ->setParameter('loanId', $loanId)
            ->setParameter('status', 'PAID')
            ->getQuery()
            ->getSingleScalarResult();

        $qb4 = $this->em->createQueryBuilder();
        $totalUnpaid = $qb4->select('COALESCE(SUM(r.monthlyPayment), 0)')
            ->from(Repayment::class, 'r')
            ->join('r.loan', 'l')
            ->where('l.loanId = :loanId')
            ->andWhere('r.status = :status')
            ->setParameter('loanId', $loanId)
            ->setParameter('status', 'UNPAID')
            ->getQuery()
            ->getSingleScalarResult();

        $total = $paidCount + $unpaidCount;

        return [
            'paidCount' => (int) $paidCount,
            'unpaidCount' => (int) $unpaidCount,
            'totalPaid' => (float) $totalPaid,
            'totalUnpaid' => (float) $totalUnpaid,
            'progressPercent' => $total > 0 ? round(($paidCount / $total) * 100) : 0,
            'isCompleted' => $unpaidCount == 0 && $total > 0,
        ];
   }


   
}