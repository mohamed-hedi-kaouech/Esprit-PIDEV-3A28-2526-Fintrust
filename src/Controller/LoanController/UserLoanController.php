<?php

namespace App\Controller\LoanController;

use App\Entity\Loan\Loan;
use App\Entity\User\User;
use App\Entity\Wallet\Wallet;
use App\Repository\Loan\LoanRepository;
use App\Service\Loan\DocRaptorService;
use App\Service\Loan\LoanService;
use App\Service\Loan\RepaymentEmailService;
use App\Service\Loan\RepaymentService;
use Doctrine\ORM\EntityManagerInterface;
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
        private LoanService $loanService,
        private RepaymentService $repaymentService,
        private EntityManagerInterface $em,
        private RepaymentEmailService $emailService,
    ) {
    }

    #[Route('/my-loans', name: 'my_loans', methods: ['GET'])]
    public function myLoans(): Response
    {
        $user = $this->getUser();

        if (!$user instanceof User) {
            $this->addFlash('error', 'Veuillez vous connecter.');

            return $this->redirectToRoute('app_login');
        }

        $loans = $this->loanService->getLoansByUser($user);
        $loansData = [];

        foreach ($loans as $loan) {
            $loansData[] = [
                'Loan' => $loan,
                'monthlyPayment' => $this->loanService->calculateMonthlyPayment($loan),
                'totalInterest' => $this->loanService->calculateTotalInterest($loan),
            ];
        }

        return $this->render('html/Loan/User/my_loans.html.twig', [
            'loansData' => $loansData,
            'user' => $user,
        ]);
    }

    #[Route('/{id}/details', name: 'user_details', requirements: ['id' => '\\d+'], methods: ['GET'])]
    public function details(int $id): Response
    {
        $loan = $this->loanService->getLoanById($id);

        if (!$loan instanceof Loan) {
            throw $this->createNotFoundException('Pret introuvable.');
        }

        return $this->render('html/Loan/User/loan_details.html.twig', [
            'loan' => $loan,
            'stats' => $this->loanService->getLoanStats($loan),
            'nextUnpaid' => $this->loanService->getNextUnpaidRepayment($loan),
            'canPay' => $loan->getStatus() === 'ACTIVE',
        ]);
    }

    #[Route('/repayment/{id}/pay', name: 'repayment_pay', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function payRepayment(int $id, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('pay_repayment_' . $id, (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Token CSRF invalide.');

            return $this->redirectToRoute('loan_my_loans');
        }

        try {
            $repayment = $this->repaymentService->findById($id);
            if ($repayment === null) {
                throw new \RuntimeException('Echeance introuvable.');
            }

            $loan = $repayment->getLoan();
            $loanId = $loan->getLoanId();
            $user = $this->getUser();

            if (!$user instanceof User) {
                $this->addFlash('error', 'Veuillez vous connecter.');

                return $this->redirectToRoute('app_login');
            }

            $userEmail = $user->getEmail();
            if ($userEmail === '') {
                $this->addFlash('error', 'Aucune adresse email n est associee a votre compte.');

                return $this->redirectToRoute('loan_user_details', ['id' => $loanId]);
            }

            /** @var Wallet|null $wallet */
            $wallet = $this->em->getRepository(Wallet::class)->findOneBy(['idUser' => $user->getId()]);
            if (!$wallet instanceof Wallet) {
                $this->addFlash('error', 'Portefeuille introuvable. Veuillez contacter le support.');

                return $this->redirectToRoute('loan_user_details', ['id' => $loanId]);
            }

            if ((float) $wallet->getSolde() < (float) $repayment->getMonthlyPayment()) {
                $this->addFlash('error', 'Solde insuffisant pour effectuer ce paiement.');

                return $this->redirectToRoute('loan_user_details', ['id' => $loanId]);
            }

            $this->repaymentService->markAsPaid($id);
            $this->emailService->sendPaymentConfirmation($repayment, $loan, $userEmail);

            $wallet->setSolde(number_format((float) $wallet->getSolde() - (float) $repayment->getMonthlyPayment(), 2, '.', ''));
            $this->em->persist($wallet);
            $this->em->persist($repayment);
            $this->em->flush();

            $this->addFlash('success', 'Echeance payee avec succes. Un email de confirmation a ete envoye a ' . $userEmail);

            return $this->redirectToRoute('loan_user_details', ['id' => $loanId]);
        } catch (\Exception $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('loan_my_loans');
        }
    }

    #[Route('/{loanId}/export-pdf', name: 'export_pdf', requirements: ['loanId' => '\\d+'], methods: ['GET'])]
    public function exportPdf(
        int $loanId,
        LoanRepository $loanRepository,
        DocRaptorService $docRaptorService,
        Environment $twig,
    ): Response {
        $loan = $loanRepository->find($loanId);

        if (!$loan instanceof Loan) {
            throw $this->createNotFoundException('Pret introuvable.');
        }

        $repaymentPlan = $this->loanService->generateRepaymentPreview($loan);
        $monthlyPayment = $this->loanService->calculateMonthlyPayment($loan);
        $totalInterest = $this->loanService->calculateTotalInterest($loan);
        $totalCost = (float) $loan->getAmount() + $totalInterest;

        $html = $twig->render('html/Loan/User/repayment_plan_pdf.html.twig', [
            'loan' => $loan,
            'repaymentPlan' => $repaymentPlan,
            'monthlyPayment' => $monthlyPayment,
            'totalInterest' => $totalInterest,
            'totalCost' => $totalCost,
            'generatedAt' => new \DateTime(),
        ]);

        $pdfContent = $docRaptorService->generatePdf($html, 'plan-remboursement-' . $loan->getLoanId() . '.pdf');

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
