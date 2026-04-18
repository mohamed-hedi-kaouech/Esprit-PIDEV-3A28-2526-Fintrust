<?php
// src/Service/Loan/RepaymentEmailService.php
namespace App\Service\Loan;

use App\Entity\Loan\Loan;
use App\Entity\Loan\Repayment;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;

class RepaymentEmailService
{
    private MailerInterface $mailer;
    private string $fromEmail;

    public function __construct(MailerInterface $mailer, string $fromEmail = 'noreply@fintrust.tn')
    {
        $this->mailer = $mailer;
        $this->fromEmail = $fromEmail;
    }

    /**
     * Send payment confirmation email
     */
    public function sendPaymentConfirmation(Repayment $repayment, Loan $loan, string $userEmail): void
    {
        $email = (new TemplatedEmail())
            ->from(new Address($this->fromEmail, 'FinTrust'))
            ->to($userEmail)
            ->subject('Confirmation de paiement - Prêt #' . $loan->getLoanId())
            ->htmlTemplate('html/Loan/User/repayment_confirmation.html.twig')
            ->context([
                'repayment' => $repayment,
                'loan' => $loan,
                'paymentDate' => new \DateTime(),
            ]);

        $this->mailer->send($email);
    }

    /**
     * Send payment receipt PDF
     */
    public function sendPaymentReceipt(Repayment $repayment, Loan $loan, string $userEmail, string $pdfContent): void
    {
        $email = (new TemplatedEmail())
            ->from(new Address($this->fromEmail, 'FinTrust'))
            ->to($userEmail)
            ->subject('Reçu de paiement - Prêt #' . $loan->getLoanId())
            ->htmlTemplate('html/Loan/User/repayment_receipt.html.twig')
            ->context([
                'repayment' => $repayment,
                'loan' => $loan,
                'paymentDate' => new \DateTime(),
            ])
            ->attach($pdfContent, 'recu-paiement-' . $repayment->getRepayId() . '.pdf', 'application/pdf');

        $this->mailer->send($email);
    }
}