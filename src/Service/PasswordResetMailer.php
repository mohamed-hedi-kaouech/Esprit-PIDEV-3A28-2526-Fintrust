<?php

namespace App\Service;

use App\Entity\User\Client\PasswordResetRequest;
use App\Entity\User\User;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class PasswordResetMailer
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly string $fintrustMailerFrom,
    ) {
    }

    public function sendResetLink(User $user, PasswordResetRequest $resetRequest, string $rawToken): void
    {
        $resetUrl = $this->urlGenerator->generate('app_password_reset_email_verify', [
            'publicId' => $resetRequest->getPublicId(),
            'token' => $rawToken,
        ], UrlGeneratorInterface::ABSOLUTE_URL);

        $email = (new TemplatedEmail())
            ->from(new Address($this->fintrustMailerFrom, 'FinTrust'))
            ->to(new Address($user->getEmail(), $user->getFullName()))
            ->subject('Reinitialisation de votre mot de passe FinTrust')
            ->htmlTemplate('emails/password_reset.html.twig')
            ->context([
                'user' => $user,
                'resetRequest' => $resetRequest,
                'resetUrl' => $resetUrl,
            ]);

        $this->mailer->send($email);
    }
}
