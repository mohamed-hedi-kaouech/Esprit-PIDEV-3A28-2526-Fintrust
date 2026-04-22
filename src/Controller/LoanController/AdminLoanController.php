<?php

namespace App\Controller\LoanController;

use App\Repository\Loan\LoanRepository;
use App\Service\Loan\LoanService;
use App\Service\Loan\LoanRiskService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

#[Route('/loan/admin')]
class AdminLoanController extends AbstractController
{
    public function __construct(
        private LoanService $loanService,
        private LoanRiskService $riskService,
        private CsrfTokenManagerInterface $csrfTokenManager,
    ) {}

    // -------------------------------------------------------------------------
    // Dashboard
    // -------------------------------------------------------------------------

    #[Route('/dashboard', name: 'admin_loan_dashboard')]
    public function dashboard(LoanRepository $loanRepo, Request $request): Response
    {
        $status = $request->query->get('status', 'all');

        $loans = match ($status) {
            'pending'   => $loanRepo->findPendingLoans(),
            'active'    => $loanRepo->findActiveLoans(),
            'completed' => $loanRepo->findByStatus('COMPLETED'),
            'rejected'  => $loanRepo->findByStatus('REJECTED'),
            default     => $loanRepo->getAllLoans(),
        };

        $counts = [
            'all'       => count($loanRepo->getAllLoans()),
            'pending'   => $loanRepo->countByStatus('PENDING'),
            'active'    => $loanRepo->countByStatus('ACTIVE') + $loanRepo->countByStatus('APPROVED'),
            'completed' => $loanRepo->countByStatus('COMPLETED'),
            'rejected'  => $loanRepo->countByStatus('REJECTED'),
        ];

        // Chart 1 — total amount per loan type
        $allLoans  = $loanRepo->getAllLoans();
        $chartData = ['personnel' => 0, 'voiture' => 0, 'logement' => 0];
        foreach ($allLoans as $l) {
            $key = strtolower($l->getLoanType());
            if (isset($chartData[$key])) {
                $chartData[$key] += (float) $l->getAmount();
            }
        }
        $chartData = array_map(fn($v) => round($v, 3), $chartData);

        // Chart 2 — risk distribution across ALL loans
        $riskCounts = ['LOW' => 0, 'MEDIUM' => 0, 'HIGH' => 0];
        foreach ($allLoans as $l) {
            $riskCounts[$this->riskService->level($l)]++;
        }

        // Risk score per loan in current view (for table column)
        $loanRisks = [];
        foreach ($loans as $l) {
            $loanRisks[$l->getLoanId()] = $this->riskService->assess($l);
        }

        return $this->render('html/Loan/Admin/dashboard.html.twig', [
            'loans'         => $loans,
            'currentFilter' => $status,
            'counts'        => $counts,
            'chartData'     => $chartData,
            'riskCounts'    => $riskCounts,
            'loanRisks'     => $loanRisks,
        ]);
    }

    // -------------------------------------------------------------------------
    // Approve / Reject
    // -------------------------------------------------------------------------

    #[Route('/loan/{id}/approve', name: 'admin_loan_approve', methods: ['POST'])]
    public function approveLoan(int $id, LoanRepository $loanRepo, Request $request): Response
    {
        if (!$this->csrfTokenManager->isTokenValid(
            new CsrfToken('approve_loan_' . $id, $request->request->get('_token'))
        )) {
            $this->addFlash('error', 'Invalid security token');
            return $this->redirectToRoute('admin_dashboard');
        }

        $loan = $loanRepo->find($id);

        if (!$loan || $loan->getStatus() !== 'PENDING') {
            $this->addFlash('error', 'Loan not found or already processed');
            return $this->redirectToRoute('admin_dashboard');
        }

        $this->loanService->approveLoan($loan->getLoanId());

        $this->addFlash('success', sprintf('Prêt #%d approuvé avec succès.', $id));
        return $this->redirectToRoute('admin_dashboard');
    }

    #[Route('/loan/{id}/reject', name: 'admin_loan_reject', methods: ['POST'])]
    public function rejectLoan(int $id, LoanRepository $loanRepo, Request $request): Response
    {
        if (!$this->csrfTokenManager->isTokenValid(
            new CsrfToken('reject_loan_' . $id, $request->request->get('_token'))
        )) {
            $this->addFlash('error', 'Invalid security token');
            return $this->redirectToRoute('admin_dashboard');
        }

        $loan = $loanRepo->find($id);

        if (!$loan || $loan->getStatus() !== 'PENDING') {
            $this->addFlash('error', 'Loan not found or already processed');
            return $this->redirectToRoute('admin_dashboard');
        }

        $loan->setStatus('REJECTED');
        $this->loanService->rejectLoan($loan->getLoanId());

        $this->addFlash('success', 'Prêt rejeté.');
        return $this->redirectToRoute('admin_dashboard');
    }

    // -------------------------------------------------------------------------
    // Review page (with risk panel)
    // -------------------------------------------------------------------------

    #[Route('/loan/{id}/review', name: 'admin_loan_review')]
    public function reviewLoan(int $id, LoanRepository $loanRepo): Response
    {
        $loan = $loanRepo->findLoanWithRepayments($id);

        if (!$loan) {
            throw $this->createNotFoundException('Loan not found');
        }

        $monthlyPayment           = $this->loanService->calculateMonthlyPayment($loan);
        $stats                    = $this->loanService->getLoanStats($loan);
        $stats['progressPercent'] = $stats['progress'];

        // Full risk assessment for the dedicated panel
        $risk = $this->riskService->assess($loan);

        return $this->render('html/Loan/Admin/loan_review.html.twig', [
            'loan'        => $loan,
            'calculator'  => [
                'monthlyPayment' => $monthlyPayment,
                'totalInterest'  => $this->loanService->calculateTotalInterest($loan),
                'totalCost'      => $monthlyPayment * $loan->getDuration(),
            ],
            'stats'       => $stats,
            'risk'        => $risk,
            'isPending'   => $loan->getStatus() === 'PENDING',
            'isActive'    => in_array($loan->getStatus(), ['ACTIVE', 'APPROVED']),
            'isCompleted' => $loan->getStatus() === 'COMPLETED',
            'isRejected'  => $loan->getStatus() === 'REJECTED',
        ]);
    }

    // -------------------------------------------------------------------------
    // Repayment history
    // -------------------------------------------------------------------------

    #[Route('/loan/{id}/repayments', name: 'admin_loan_repayments')]
    public function viewRepayments(int $id, LoanRepository $loanRepo): Response
    {
        $loan = $loanRepo->findLoanWithRepayments($id);

        if (!$loan) {
            throw $this->createNotFoundException('Loan not found');
        }

        $stats                    = $this->loanService->getLoanStats($loan);
        $stats['progressPercent'] = $stats['progress'];
        $stats['isCompleted']     = $loan->getStatus() === 'COMPLETED';

        $nextUnpaid = $this->loanService->getNextUnpaidRepayment($loan);

        return $this->render('html/Loan/admin/repayments.html.twig', [
            'loan'       => $loan,
            'repayments' => $loan->getRepayments(),
            'stats'      => $stats,
            'nextUnpaid' => $nextUnpaid,
        ]);
    }
}