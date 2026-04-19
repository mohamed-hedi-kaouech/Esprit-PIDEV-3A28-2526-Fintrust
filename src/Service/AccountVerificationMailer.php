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

        $code = $user->getEmailVerificationCode();
        $expiresAt = $user->getEmailVerificationExpiresAt();

        if ($code === null || $expiresAt === null) {
            throw new \RuntimeException('Le code de verification FinTrust est indisponible pour ce compte. Regenerer un nouveau code puis reessayer.');
        }

        $email = (new TemplatedEmail())
            ->from(new Address($this->fintrustMailerFrom, 'FinTrust'))
            ->to(new Address($user->getEmail(), $user->getFullName()))
            ->subject('FinTrust - Confirmez votre inscription')
            ->htmlTemplate('emails/account_verification.html.twig')
            ->text($this->buildPlainTextMessage($user, $code, $expiresAt))
            ->context([
                'user' => $user,
                'code' => $code,
                'expiresAt' => $expiresAt,
                'loginUrl' => $this->fintrustLoginUrl,
            ]);

        $this->mailer->send($email);
    }

    public function isMailerDisabled(): bool
    {
        $dsn = trim($this->fintrustMailerDsn);

        return $dsn === '' || $dsn === 'null://null';
    }

    private function buildPlainTextMessage(User $user, string $code, \DateTimeInterface $expiresAt): string
    {
        return implode("\n", [
            'FinTrust - Verification de votre compte',
            '',
            'Bonjour ' . $user->getPrenom() . ',',
            'Votre compte FinTrust a bien ete cree.',
            'Utilisez le code de verification suivant pour finaliser votre inscription :',
            '',
            'CODE : ' . $code,
            '',
            'Ce code expire le ' . $expiresAt->format('d/m/Y') . ' a ' . $expiresAt->format('H:i') . '.',
            'Acces FinTrust : ' . $this->fintrustLoginUrl,
            '',
            'Ne partagez jamais ce code avec un tiers.',
            'FinTrust ne vous demandera jamais ce code par telephone.',
        ]);
    }
}
