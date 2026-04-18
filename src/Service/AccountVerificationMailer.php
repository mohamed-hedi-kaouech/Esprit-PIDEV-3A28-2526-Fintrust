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
            $this->storeLocalPreview($user);

            throw new \RuntimeException('Le transport e-mail local est desactive. Un apercu du code a ete enregistre pour cette machine.');
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

    /**
     * @return array<string, string>|null
     */
    public function getLatestPreviewForEmail(string $email): ?array
    {
        $previewPath = $this->getPreviewPath($email);
        if (!is_file($previewPath)) {
            return null;
        }

        $payload = json_decode((string) file_get_contents($previewPath), true);
        if (!is_array($payload)) {
            return null;
        }

        $code = isset($payload['code']) ? trim((string) $payload['code']) : '';
        if ($code === '') {
            return null;
        }

        return [
            'code' => $code,
            'generatedAt' => isset($payload['generatedAt']) ? (string) $payload['generatedAt'] : '',
            'email' => isset($payload['email']) ? (string) $payload['email'] : $email,
        ];
    }

    private function storeLocalPreview(User $user): void
    {
        $previewDir = $this->projectDir . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'mail-previews';
        if (!is_dir($previewDir)) {
            mkdir($previewDir, 0777, true);
        }

        $payload = [
            'email' => $user->getEmail(),
            'code' => $user->getEmailVerificationCode(),
            'generatedAt' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ];

        file_put_contents(
            $this->getPreviewPath($user->getEmail()),
            json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
    }

    private function getPreviewPath(string $email): string
    {
        $safeEmail = preg_replace('/[^a-z0-9_\-\.@]/i', '_', strtolower(trim($email))) ?: 'verification';

        return $this->projectDir . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'mail-previews' . DIRECTORY_SEPARATOR . $safeEmail . '.json';
    }
}
