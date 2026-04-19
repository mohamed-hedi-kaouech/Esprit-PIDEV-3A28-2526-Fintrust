<?php

namespace App\Service;

use App\Entity\User\User;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;

class AccountVerificationMailer
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly string $fintrustMailerFrom,
        private readonly string $fintrustLoginUrl,
        private readonly string $fintrustMailerDsn,
        private readonly string $projectDir,
    ) {}

    public function sendVerificationCode(User $user): void
    {
        if ($this->isMailerDisabled()) {
            throw new \RuntimeException('Le transport e-mail FinTrust est desactive. Configurez MAILER_DSN pour envoyer le code de verification.');
        }

        $email = (new TemplatedEmail())
            ->from(new Address($this->fintrustMailerFrom, 'FinTrust'))
            ->to(new Address($user->getEmail(), $user->getFullName()))
            ->subject('FinTrust - Confirmez votre inscription')
            ->htmlTemplate('emails/account_verification.html.twig')
            ->context([
                'user' => $user,
                'code' => $user->getEmailVerificationCode(),
                'expiresAt' => $user->getEmailVerificationExpiresAt(),
                'loginUrl' => $this->fintrustLoginUrl,
            ]);

        $this->mailer->send($email);
    }

    public function isMailerDisabled(): bool
    {
        $dsn = trim($this->fintrustMailerDsn);

        return $dsn === '' || $dsn === 'null://null';
    }
}
