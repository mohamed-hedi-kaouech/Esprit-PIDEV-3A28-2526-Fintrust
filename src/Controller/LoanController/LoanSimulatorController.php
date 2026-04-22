<?php

namespace App\Controller\LoanController;

use App\Entity\Loan\Loan;
use App\Form\Loan\LoanSimulatorType;
use App\Service\Loan\LoanService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/loan', name: 'loan_')]
class LoanSimulatorController extends AbstractController
{
    public function __construct(
        private LoanService $loanService,
    ) {}

    /**
     * Step 1 – Simulator form
     *
     * Route: GET|POST /loan/simulator
     * Name:  loan_simulator
     */
    #[Route('/simulator', name: 'simulator', methods: ['GET', 'POST'])]
    public function simulator(Request $request): Response
    {
        $loan = new Loan();
        $loan->setInterestRate(8.25);
        $loan->setLoanType('PERSONNEL');

        $form = $this->createForm(LoanSimulatorType::class, $loan);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $maxAmounts = [
                'PERSONNEL' => 25000,
                'VOITURE'   => 50000,
                'LOGEMENT'  => 200000,
            ];

            $type   = $loan->getLoanType();
            $max    = $maxAmounts[$type] ?? 25000;
            $amount = (float) $loan->getAmount();

            if ($amount > $max) {
                $this->addFlash('error', sprintf(
                    'Le montant maximal pour un prêt %s est %s TND.',
                    $type,
                    number_format($max, 0, ',', ' ')
                ));

                return $this->render('html/Loan/User/simulator.html.twig', ['form' => $form]);
            }

            $request->getSession()->set('loan_simulation', [
                'amount'       => $loan->getAmount(),
                'duration'     => $loan->getDuration(),
                'interestRate' => $loan->getInterestRate(),
                'loanType'     => $loan->getLoanType(),
            ]);

            return $this->redirectToRoute('loan_preview');
        }

        return $this->render('html/Loan/User/simulator.html.twig', ['form' => $form]);
    }

    /**
     * Step 2 – Repayment preview
     *
     * Route: GET /loan/preview
     * Name:  loan_preview
     */
    #[Route('/preview', name: 'preview', methods: ['GET'])]
    public function preview(Request $request): Response
    {
        $data = $request->getSession()->get('loan_simulation');

        if (!$data) {
            $this->addFlash('error', 'Veuillez d\'abord lancer une simulation.');
            return $this->redirectToRoute('loan_simulator');
        }

        $loan = new Loan();
        $loan->setAmount($data['amount']);
        $loan->setDuration($data['duration']);
        $loan->setInterestRate($data['interestRate']);
        $loan->setLoanType($data['loanType']);

        $repaymentPlan  = $this->loanService->generateRepaymentPreview($loan);
        $monthlyPayment = $this->loanService->calculateMonthlyPayment($loan);
        $totalInterest  = $this->loanService->calculateTotalInterest($loan);
        $totalCost      = (float) $data['amount'] + $totalInterest;

        return $this->render('html/Loan/User/preview.html.twig', [
            'loan'           => $loan,
            'repaymentPlan'  => $repaymentPlan,
            'monthlyPayment' => $monthlyPayment,
            'totalInterest'  => $totalInterest,
            'totalCost'      => $totalCost,
        ]);
    }

    /**
     * Step 3 – Confirm & create the loan (no user for now)
     * TODO: wire $loan->setUser($this->getUser()) after user module is merged
     *
     * Route: POST /loan/confirm
     * Name:  loan_confirm
     */
    #[Route('/confirm', name: 'confirm', methods: ['POST'])]
    public function confirm(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('confirm_loan', $request->request->get('_token'))) {
            $this->addFlash('error', 'Token CSRF invalide.');
            return $this->redirectToRoute('loan_simulator');
        }

        $data = $request->getSession()->get('loan_simulation');

        if (!$data) {
            $this->addFlash('error', 'Session expirée. Veuillez relancer la simulation.');
            return $this->redirectToRoute('loan_simulator');
        }

        $loan = new Loan();
        $loan->setAmount($data['amount']);
        $loan->setDuration($data['duration']);
        $loan->setInterestRate($data['interestRate']);
        $loan->setLoanType($data['loanType']);
        $loan->setStatus('PENDING');
        // TODO: $loan->setUser($this->getUser()); — add after user module merge

        $this->loanService->createLoan($loan);

        $request->getSession()->remove('loan_simulation');

        $this->addFlash('success', 'Votre demande de prêt a été soumise avec succès et est en cours d\'examen.');

        return $this->redirectToRoute('loan_my_loans');
    }
}