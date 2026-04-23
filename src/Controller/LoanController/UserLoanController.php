<?php
// src/Controller/LoanController/UserLoanController.php

namespace App\Controller\LoanController;

use App\Repository\Loan\LoanRepository;
use App\Service\Loan\LoanService;
use App\Service\Loan\RepaymentService;
use App\Service\Loan\DocRaptorService;
use App\Entity\Wallet\Wallet;
use App\Entity\Loan\Loan;
use App\Service\Loan\RepaymentEmailService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Repository\User\UserRepository;
use phpDocumentor\Reflection\Types\This;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Annotation\Route;
use Twig\Environment;

#[Route('/loan', name: 'loan_')]
class UserLoanController extends AbstractController
{
    public function __construct(
        private LoanService      $loanService,
        private RepaymentService $repaymentService,
        private EntityManagerInterface $em,     
        private UserRepository $userRepository,
        private RepaymentEmailService $emailService,
        private string $testUserEmail,
    ) {}

     
    #[Route('/my-loans', name: 'my_loans', methods: ['GET'])]
    public function myLoans(): Response
    {
        $user = $this->getUser();
        
        if (!$user) {
            $this->addFlash('error', 'Veuillez vous connecter.');
            return $this->redirectToRoute('app_login');
        }

        // SIMPLE: Just get loans by user
        $loans = $this->loanService->getLoansByUser($user);

        $loansData = [];
        foreach ($loans as $loan) {
            $monthly = $this->loanService->calculateMonthlyPayment($loan);
            $totalInt = $this->loanService->calculateTotalInterest($loan);
            
            $loansData[] = [
                'Loan' => $loan,
                'monthlyPayment' => $monthly,
                'totalInterest' => $totalInt,
            ];
        }

    return $this->render('html/Loan/User/my_loans.html.twig', [
        'loansData' => $loansData,
        'user' => $user,
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
                    // ── Wallet ───────────────────────────────────────────
            $loan = $repayment->getLoan();
            $loanId = $loan->getLoanId();
            $user = $this->getUser();
        
            if (!$user) {
            $this->addFlash('error', 'Veuillez vous connecter.');
                return $this->redirectToRoute('app_login');
            }

              $wallet = $this->em->getRepository(Wallet::class)
            ->findOneBy(['idUser' => $user]);

            if (!$wallet) {
                $this->addFlash('error', 'Portefeuille introuvable. Veuillez contacter le support.');
                return $this->redirectToRoute('loan_user_details', ['id' => $loanId]);
            }
            if ($wallet->getSolde() < $repayment->getMonthlyPayment()) {
                $this->addFlash('error', 'Solde insuffisant pour effectuer ce paiement.');
                return $this->redirectToRoute('loan_user_details', ['id' => $loanId]);
            }
            // Mark as paid
            $this->repaymentService->markAsPaid($id);

            // Send confirmation email to test email (until user module is ready)
            $this->emailService->sendPaymentConfirmation($repayment, $loan, $this->testUserEmail);

            $this->addFlash('success', 'Échéance payée avec succès. Un email de confirmation a été envoyé à ' . $this->testUserEmail);
            
                        // ── Update wallet ────────────────────────────────────
            $wallet->setSolde($wallet->getSolde() - $repayment->getMonthlyPayment());

            $this->em->persist($wallet);
            $this->em->persist($repayment);
            $this->em->flush();
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