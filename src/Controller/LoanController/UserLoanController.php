<?php

namespace App\Controller\LoanController;

use App\Service\Loan\LoanService;
use App\Service\Loan\RepaymentService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/loan', name: 'loan_')]
class UserLoanController extends AbstractController
{
    public function __construct(
        private LoanService      $loanService,
        private RepaymentService $repaymentService,
    ) {}

    /**
     * My Loans list — returns ALL loans for now (no user filter)
     * TODO: filter by $this->getUser()->getId() after user module merge
     *
     * Route: GET /loan/my-loans
     * Name:  my_loans              ← matches path('my_loans') in loan_details.html.twig
     */
    #[Route('/my-loans', name: 'my_loans', methods: ['GET'])]
    public function myLoans(): Response
    {
        // TODO: replace getAllLoans() with getLoansByUserId($this->getUser()->getId())
        $loans = $this->loanService->getAllLoans();

        return $this->render('html/Loan/User/my_loans.html.twig', [
            'loans' => $loans,
        ]);
    }

    /**
     * Loan detail + repayment schedule
     *
     * Route: GET /loan/{id}/details
     * Name:  loan_user_details
     */
    #[Route('/{id}/details', name: 'user_details', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function details(int $id): Response
    {
        $loan = $this->loanService->getLoanById($id);

        if (!$loan) {
            throw $this->createNotFoundException('Prêt introuvable.');
        }

        // TODO: add ownership check after user module merge:
        // if ($loan->getUser()->getId() !== $this->getUser()->getId()) {
        //     throw $this->createAccessDeniedException();
        // }

        $stats      = $this->loanService->getLoanStats($loan);
        $nextUnpaid = $this->loanService->getNextUnpaidRepayment($loan);

        // canPay drives the Action column and "Payer maintenant" banner in the template
        $canPay = $loan->getStatus() === 'ACTIVE';

        return $this->render('html/Loan/User/loan_details.html.twig', [
            'loan'       => $loan,
            'stats'      => $stats,
            'nextUnpaid' => $nextUnpaid,
            'canPay'     => $canPay,
        ]);
    }

    /**
     * Pay a single installment (sequential rule enforced in RepaymentService)
     *
     * Route: POST /loan/repayment/{id}/pay
     * Name:  repayment_pay         ← matches path('repayment_pay', {id: repayment.repayId}) in template
     */
    #[Route('/repayment/{id}/pay', name: 'repayment_pay', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function payRepayment(int $id, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('pay_repayment_' . $id, $request->request->get('_token'))) {
            $this->addFlash('error', 'Token CSRF invalide.');
            return $this->redirectToRoute('my_loans');
        }

        try {
            $repayment = $this->repaymentService->findById($id);

            if (!$repayment) {
                throw new \Exception('Échéance introuvable.');
            }

            $loanId = $repayment->getLoan()->getLoanId();

            // TODO: add ownership check after user module merge:
            // if ($repayment->getLoan()->getUser()->getId() !== $this->getUser()->getId()) {
            //     throw new \Exception('Accès refusé.');
            // }

            // markAsPaid handles sequential validation + loan completion check
            $this->repaymentService->markAsPaid($id);

            $this->addFlash('success', 'Échéance payée avec succès.');

            return $this->redirectToRoute('loan_user_details', ['id' => $loanId]);

        } catch (\Exception $e) {
            $this->addFlash('error', $e->getMessage());
            return $this->redirectToRoute('my_loans');
        }
    }
}