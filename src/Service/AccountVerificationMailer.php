<?php

namespace App\Service;

use App\Entity\User\User;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

class AccountVerificationMailer
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly string $fintrustMailerFrom,
        private readonly string $fintrustLoginUrl,
    ) {
    }

    public function sendVerificationCode(User $user): void
    {
        $code = (string) $user->getEmailVerificationCode();
        $expiresAt = $user->getEmailVerificationExpiresAt();
        $recipientName = trim($user->getFullName());
        $recipient = $recipientName !== '' ? sprintf('"%s" <%s>', $recipientName, $user->getEmail()) : $user->getEmail();
        $expiresLabel = $expiresAt instanceof \DateTimeInterface
            ? $expiresAt->format('d/m/Y a H:i')
            : 'dans 10 minutes';
        $loginUrl = $this->fintrustLoginUrl;
        $salutationName = trim($user->getPrenom()) !== ''
            ? trim($user->getPrenom())
            : ($recipientName !== '' ? $recipientName : 'client');

        $email = (new Email())
            ->from($this->fintrustMailerFrom)
            ->replyTo($this->fintrustMailerFrom)
            ->returnPath($this->fintrustMailerFrom)
            ->to($recipient)
            ->subject('Verification de votre compte FinTrust')
            ->text(
                "Bonjour,\n\n"
                . "Votre code de verification FinTrust est : {$code}\n\n"
                . "Ce code expire le {$expiresLabel}.\n\n"
                . "Saisissez ce code sur la page de verification de votre compte.\n"
                . "Lien de connexion : {$loginUrl}\n\n"
                . "Equipe FinTrust"
            )
            ->html(
                '<!DOCTYPE html><html lang="fr"><head><meta charset="UTF-8"><title>Verification FinTrust</title></head><body style="margin:0;padding:24px;background:#f4f8ff;font-family:Arial,sans-serif;color:#0f172a;">'
                . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:640px;margin:0 auto;background:#ffffff;border-radius:18px;overflow:hidden;border:1px solid #dbeafe;">'
                . '<tr><td style="padding:28px 32px;background:#1560BD;color:#ffffff;"><h1 style="margin:0;font-size:26px;">FinTrust</h1><p style="margin:8px 0 0;font-size:14px;">Verification de votre adresse e-mail</p></td></tr>'
                . '<tr><td style="padding:32px;">'
                . '<p style="margin:0 0 16px;font-size:16px;">Bonjour ' . htmlspecialchars($salutationName, ENT_QUOTES, 'UTF-8') . ',</p>'
                . '<p style="margin:0 0 16px;font-size:16px;line-height:1.6;">Utilisez le code ci-dessous pour finaliser votre inscription FinTrust.</p>'
                . '<div style="margin:0 0 20px;padding:18px;border-radius:14px;background:#eff6ff;border:1px solid #bfdbfe;text-align:center;">'
                . '<div style="font-size:13px;font-weight:700;color:#1d4ed8;text-transform:uppercase;letter-spacing:.08em;margin-bottom:10px;">Code de verification</div>'
                . '<div style="font-size:34px;font-weight:800;letter-spacing:8px;color:#0f172a;">' . htmlspecialchars($code, ENT_QUOTES, 'UTF-8') . '</div>'
                . '</div>'
                . '<p style="margin:0 0 16px;font-size:15px;line-height:1.6;color:#475569;">Ce code expire le ' . htmlspecialchars($expiresLabel, ENT_QUOTES, 'UTF-8') . '.</p>'
                . '<p style="margin:0 0 22px;text-align:center;"><a href="' . htmlspecialchars($loginUrl, ENT_QUOTES, 'UTF-8') . '" style="display:inline-block;padding:12px 22px;border-radius:12px;background:#1560BD;color:#ffffff;text-decoration:none;font-weight:700;">Acceder a FinTrust</a></p>'
                . '<p style="margin:0;font-size:15px;line-height:1.6;color:#475569;">Si vous n etes pas a l origine de cette inscription, ignorez simplement cet e-mail.</p>'
                . '</td></tr></table></body></html>'
            );

        $this->mailer->send($email);
    }
}
