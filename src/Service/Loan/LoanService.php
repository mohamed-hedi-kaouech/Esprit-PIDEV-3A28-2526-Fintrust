<?php

namespace App\Service\Loan;

use App\Entity\Loan\Loan;
use App\Entity\User\User;
use App\Entity\Loan\Repayment;
use App\Repository\Loan\LoanRepository;
use App\Repository\Loan\RepaymentRepository;
use Doctrine\ORM\EntityManagerInterface;
use Exception;

class LoanService
{
    private EntityManagerInterface $em;
    private LoanRepository $loanRepository;
    private RepaymentRepository $repaymentRepository;
    public function __construct(
        EntityManagerInterface $em,
        LoanRepository $loanRepository,
        RepaymentRepository $repaymentRepository
    ) {
        $this->em = $em;
        $this->loanRepository = $loanRepository;
        $this->repaymentRepository = $repaymentRepository;
    }
    /**
     * Create a new loan and generate repayment plan
     */
    public function createLoan(Loan $loan): Loan
    {
        // Set initial values
        $loan->setRemainingPrincipal($loan->getAmount());
        $loan->setCreatedAt(new \DateTime());
        
        // Save the loan
        $this->em->persist($loan);
        $this->em->flush();

        // Generate repayment plan
        $this->generateRepaymentPlan($loan);

        return $loan;
    }

    /**
     * @return array<int, Loan>
     */
    public function getLoansByUser(User $user): array
    {
        return $this->loanRepository->findByUser($user);
    }
    /**
     * Delete a loan (repayments will be cascade deleted)
     */
    public function deleteLoan(int $loanId): void
    {
        $loan = $this->loanRepository->find($loanId);
        if ($loan) {
            $this->em->remove($loan);
            $this->em->flush();
        }
    }

    /**
     * Get all loans
     *
     * @return array<int, Loan>
     */
    public function getAllLoans(): array
    {
        return $this->loanRepository->findAll();
    }

    /**
     * Get loan by ID
     */
    public function getLoanById(int $id): ?Loan
    {
        return $this->loanRepository->findOneBy(['loanId' => $id]);
    }

    /**
     * Get loans by user ID
     *
     * @return array<int, Loan>
     */
    public function getLoansByUserId(int $userId): array
    {
        return $this->loanRepository->findByUserId($userId);
    }

    /**
     * Get loans by status
     *
     * @return array<int, Loan>
     */
    public function getLoansByStatus(string $status): array
    {
        return $this->loanRepository->findByStatus($status);
    }

    /**
     * Approve a loan (set to ACTIVE)
     */
    public function approveLoan(int $loanId): void
    {
        $loan = $this->loanRepository->find($loanId);
        if ($loan) {
            $loan->setStatus('ACTIVE');
            $this->em->flush();
        }
    }

    /**
     * Reject/delete a loan
     */
    public function rejectLoan(int $loanId): void
    {
        $this->deleteLoan($loanId);
    }

    /**
     * Calculate monthly payment using amortization formula
     */
    public function calculateMonthlyPayment(Loan $loan): float
    {
        $monthlyRate = (float) $loan->getInterestRate() / 100 / 12;
        $amount = (float) $loan->getAmount();
        $duration = $loan->getDuration();

        if ($monthlyRate == 0) {
            return $amount / $duration;
        }

        return $amount * ($monthlyRate * pow(1 + $monthlyRate, $duration)) / (pow(1 + $monthlyRate, $duration) - 1);
    }

    /**
     * Generate repayment plan for a loan
     *
     * @return array<int, Repayment>
     */
    public function generateRepaymentPlan(Loan $loan): array
    {
        $repayments = [];
        $monthlyRate = (float) $loan->getInterestRate() / 100 / 12;
        $monthlyPayment = $this->calculateMonthlyPayment($loan);
        $balance = (float) $loan->getAmount();

        for ($i = 1; $i <= $loan->getDuration(); $i++) {
            $interest = $balance * $monthlyRate;
            $capital = $monthlyPayment - $interest;
            $remaining = $balance - $capital;

            if ($remaining < 0) {
                $remaining = 0;
            }

            $repayment = new Repayment();
            $repayment->setLoan($loan);
            $repayment->setMonth($i);
            $repayment->setStartingBalance(number_format($balance, 2, '.', ''));
            $repayment->setMonthlyPayment(number_format($monthlyPayment, 2, '.', ''));
            $repayment->setCapitalPart(number_format($capital, 2, '.', ''));
            $repayment->setInterestPart(number_format($interest, 2, '.', ''));
            $repayment->setRemainingBalance(number_format($remaining, 2, '.', ''));
            $repayment->setStatus('UNPAID');

            $this->em->persist($repayment);
            $repayments[] = $repayment;

            $balance = $remaining;
        }

        $this->em->flush();

        return $repayments;
    }
    /**
     * @return list<array{
     *     month: int,
     *     startingBalance: float,
     *     monthlyPayment: float,
     *     capitalPart: float,
     *     interestPart: float,
     *     remainingBalance: float
     * }>
     */
    public function generateRepaymentPreview(Loan $loan): array
    {
        $plan = [];
        $monthlyRate = (float) $loan->getInterestRate() / 100 / 12;
        $monthlyPayment = $this->calculateMonthlyPayment($loan);
        $balance = (float) $loan->getAmount();

        for ($i = 1; $i <= $loan->getDuration(); $i++) {
            $interest = $balance * $monthlyRate;
            $capital = $monthlyPayment - $interest;
            $remaining = max(0, $balance - $capital);

            $plan[] = [
                'month' => $i,
                'startingBalance' => $balance,
                'monthlyPayment' => $monthlyPayment,
                'capitalPart' => $capital,
                'interestPart' => $interest,
                'remainingBalance' => $remaining,
            ];

            $balance = $remaining;
        }

        return $plan;
    }
    public function getNextUnpaidRepayment(Loan $loan): ?Repayment
    {
        foreach ($loan->getRepayments() as $repayment) {
            if ($repayment->getStatus() === 'UNPAID') {
                return $repayment;
            }
        }

        return null;
    }
    /**
     * Get loan statistics for dashboard
     *
     * @return array{total: int, active: int, pending: int, completed: int}
     */
    public function getStatistics(): array
    {
        $total = $this->loanRepository->countByStatus('ACTIVE') 
               + $this->loanRepository->countByStatus('COMPLETED');
        
        return [
            'total' => $total,
            'active' => $this->loanRepository->countByStatus('ACTIVE'),
            'pending' => $this->loanRepository->countByStatus('PENDING'),
            'completed' => $this->loanRepository->countByStatus('COMPLETED'),
        ];
    }
    public function calculateTotalInterest(Loan $loan): float
    {
        $monthlyPayment = $this->calculateMonthlyPayment($loan);
        $totalPaid = $monthlyPayment * $loan->getDuration();
        
        return $totalPaid - (float) $loan->getAmount();
    }

    // ======================
    // APPROVAL & PROCESSING
    // ======================


    /**
     * Mark repayment as paid
     */
    public function markRepaymentPaid(Repayment $repayment): void
    {
        $repayment->setStatus('PAID');
        
        // Update loan remaining principal
        $loan = $repayment->getLoan();
        $currentRemaining = (float) $loan->getRemainingPrincipal();
        $capitalPaid = (float) $repayment->getCapitalPart();
        $newRemaining = max(0, $currentRemaining - $capitalPaid);
        
        $loan->setRemainingPrincipal(number_format($newRemaining, 2, '.', ''));
        
        // Check if loan is fully paid
        $allPaid = true;
        foreach ($loan->getRepayments() as $r) {
            if ($r->getStatus() !== 'PAID') {
                $allPaid = false;
                break;
            }
        }
        
        if ($allPaid) {
            $loan->setStatus('COMPLETED');
        }
        
        $this->em->flush();
    }

    /**
     * Get loan statistics for dashboard
     *
     * @return array{
     *     paidCount: int,
     *     unpaidCount: int,
     *     totalPaid: float,
     *     totalUnpaid: float,
     *     progress: float|int,
     *     monthlyPayment: float,
     *     totalInterest: float
     * }
     */
    public function getLoanStats(Loan $loan): array
    {
        $repayments = $loan->getRepayments();
        $paidCount = 0;
        $unpaidCount = 0;
        $totalPaid = 0;
        $totalUnpaid = 0;

        foreach ($repayments as $repayment) {
            if ($repayment->getStatus() === 'PAID') {
                $paidCount++;
                $totalPaid += (float) $repayment->getMonthlyPayment();
            } else {
                $unpaidCount++;
                $totalUnpaid += (float) $repayment->getMonthlyPayment();
            }
        }

        $totalInstallments = $paidCount + $unpaidCount;
        
        return [
            'paidCount' => $paidCount,
            'unpaidCount' => $unpaidCount,
            'totalPaid' => $totalPaid,
            'totalUnpaid' => $totalUnpaid,
            'progress' => $totalInstallments > 0 ? round(($paidCount / $totalInstallments) * 100) : 0,
            'monthlyPayment' => $this->calculateMonthlyPayment($loan),
            'totalInterest' => $this->calculateTotalInterest($loan),
        ];
    }

    public function payRepayment(int $repaymentId, int $userId): void
    {
        $repayment = $this->repaymentRepository->find($repaymentId);

        if (!$repayment instanceof Repayment) {
            throw new Exception('Repayment not found');
        }

        $loan = $repayment->getLoan();
        $user = $loan->getUser();

        if (!$user instanceof User || $user->getId() !== $userId) {
            throw new Exception('Access denied');
        }

        if ($loan->getStatus() !== 'ACTIVE') {
            throw new Exception('Loan is not active');
        }

        foreach ($loan->getRepayments() as $existingRepayment) {
            if ($existingRepayment->getMonth() < $repayment->getMonth() && $existingRepayment->getStatus() === 'UNPAID') {
                throw new Exception('Previous installments must be paid first');
            }
        }

        $this->markRepaymentPaid($repayment);
    }
        
}
