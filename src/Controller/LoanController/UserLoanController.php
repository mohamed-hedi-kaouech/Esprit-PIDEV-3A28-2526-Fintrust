<?php
// src/Controller/LoanController/UserLoanController.php

namespace App\Controller\LoanController;

use App\Repository\Loan\LoanRepository;
use App\Service\Loan\LoanService;
use App\Service\Loan\RepaymentService;
use App\Service\Loan\DocRaptorService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Annotation\Route;
use Twig\Environment;

#[Route('/loan', name: 'loan_')]
class UserLoanController extends AbstractController
{
    public function __construct(
        private LoanService      $loanService,
        private RepaymentService $repaymentService,
    ) {}

    #[Route('/my-loans', name: 'my_loans', methods: ['GET'])]
    public function myLoans(): Response
    {
        $loans = $this->loanService->getAllLoans();
        return $this->render('html/Loan/User/my_loans.html.twig', [
            'loans' => $loans,
        ]);
    }

    #[Route('/{id}/details', name: 'user_details', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function details(int $id): Response
    {
        $loan = $this->loanService->getLoanById($id);

        if (!$loan) {
            throw $this->createNotFoundException('Prêt introuvable.');
        }

        $stats      = $this->loanService->getLoanStats($loan);
        $nextUnpaid = $this->loanService->getNextUnpaidRepayment($loan);
        $canPay     = $loan->getStatus() === 'ACTIVE';

        return $this->render('html/Loan/User/loan_details.html.twig', [
            'loan'       => $loan,
            'stats'      => $stats,
            'nextUnpaid' => $nextUnpaid,
            'canPay'     => $canPay,
        ]);
    }

    #[Route('/repayment/{id}/pay', name: 'repayment_pay', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function payRepayment(int $id, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('pay_repayment_' . $id, $request->request->get('_token'))) {
            $this->addFlash('error', 'Token CSRF invalide.');
            return $this->redirectToRoute('loan_my_loans');
        }

        try {
            $repayment = $this->repaymentService->findById($id);
            if (!$repayment) {
                throw new \Exception('Échéance introuvable.');
            }

            $loanId = $repayment->getLoan()->getLoanId();
            $this->repaymentService->markAsPaid($id);

            $this->addFlash('success', 'Échéance payée avec succès.');
            return $this->redirectToRoute('loan_user_details', ['id' => $loanId]);

        } catch (\Exception $e) {
            $this->addFlash('error', $e->getMessage());
            return $this->redirectToRoute('loan_my_loans');
        }
    }

        #[Route('/{loanId}/export-pdf', name: 'export_pdf', requirements: ['loanId' => '\d+'], methods: ['GET'])]
        public function exportPdf(
            int $loanId,
            LoanRepository $loanRepository,
            DocRaptorService $docRaptorService,
            Environment $twig
        ): Response {
            $loan = $loanRepository->find($loanId);
            
            if (!$loan) {
                throw $this->createNotFoundException('Prêt introuvable.');
            }

            // Generate repayment plan
            $repaymentPlan = $this->loanService->generateRepaymentPreview($loan);
            $monthlyPayment = $this->loanService->calculateMonthlyPayment($loan);
            $totalInterest = $this->loanService->calculateTotalInterest($loan);
            $totalCost = $loan->getAmount() + $totalInterest;

            // Render HTML template for PDF
            $html = $twig->render('html/Loan/User/repayment_plan_pdf.html.twig', [
                'loan' => $loan,
                
                'repaymentPlan' => $repaymentPlan,
                'monthlyPayment' => $monthlyPayment,
                'totalInterest' => $totalInterest,
                'totalCost' => $totalCost,
                'generatedAt' => new \DateTime(),
            ]);

            // Generate PDF
            $pdfContent = $docRaptorService->generatePdf($html, 'plan-remboursement-' . $loan->getLoanId() . '.pdf');

            // Create response
            $response = new Response($pdfContent);
            $disposition = $response->headers->makeDisposition(
                ResponseHeaderBag::DISPOSITION_ATTACHMENT,
                'plan-remboursement-' . $loan->getLoanId() . '.pdf'
            );
            
            $response->headers->set('Content-Type', 'application/pdf');
            $response->headers->set('Content-Disposition', $disposition);

            return $response;
        }
}