<?php

namespace App\Service;

use App\Entity\User\Client\Notification;
use App\Entity\User\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;

/**
 * Service - Notifications internes et e-mail.
 *
 * Cree des notifications stockees en base de donnees
 * et peut aussi envoyer un e-mail selon le canal choisi.
 */
class NotificationService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MailerInterface $mailer,
        private readonly string $fintrustMailerFrom,
        private readonly string $fintrustMailerDsn,
    ) {}

    /**
     * @param string $type INFO | SUCCESS | WARNING | ERROR
     * @param string $channel INTERNE | EMAIL
     */
    public function notify(
        User $user,
        string $message,
        string $type = 'INFO',
        string $channel = 'INTERNE',
        ?string $emailSubject = null,
    ): void
    {
        $this->createInternalNotification($user, $message, $type);

        if (strtoupper($channel) === 'EMAIL') {
            $this->sendEmailNotification($user, $message, $type, $emailSubject);
        }
    }

    public function notifyKycApproved(User $user): void
    {
        $this->notify(
            $user,
            'Votre dossier KYC a ete approuve. Votre compte FinTrust est maintenant pleinement actif.',
            'SUCCESS'
        );
    }

    public function notifyKycRefused(User $user, string $reason): void
    {
        $this->notify(
            $user,
            "Votre dossier KYC a ete refuse. Motif : {$reason}",
            'ERROR'
        );
    }

    public function notifyKycSubmitted(User $user): void
    {
        $this->notify(
            $user,
            'Votre dossier KYC a bien ete recu. Il sera examine dans les plus brefs delais.',
            'INFO'
        );
    }

    public function notifyRiskEscalation(User $user, string $riskLevel): void
    {
        $this->notify(
            $user,
            "Votre profil de risque a ete revalue au niveau {$riskLevel}. Certaines actions sensibles peuvent etre temporairement limitees.",
            $riskLevel === User::RISK_CRITICAL ? 'ERROR' : 'WARNING'
        );
    }

    public function markAsReadForUser(int $notificationId, User $user): bool
    {
        /** @var Notification|null $notification */
        $notification = $this->em->getRepository(Notification::class)->find($notificationId);

        if (!$notification || $notification->getUser()->getId() !== $user->getId()) {
            return false;
        }

        if ($notification->isRead()) {
            return true;
        }

        $notification->setIsRead(true);
        $this->em->flush();

        return true;
    }

    public function markAllAsReadForUser(User $user): int
    {
        /** @var Notification[] $notifications */
        $notifications = $this->em->getRepository(Notification::class)
            ->findBy(['user' => $user, 'isRead' => false]);

        $updated = 0;

        foreach ($notifications as $notification) {
            $notification->setIsRead(true);
            $updated++;
        }

        if ($updated > 0) {
            $this->em->flush();
        }

        return $updated;
    }

    public function countUnreadForUser(User $user): int
    {
        return $this->em->getRepository(Notification::class)->count([
            'user' => $user,
            'isRead' => false,
        ]);
    }

    private function createInternalNotification(User $user, string $message, string $type): void
    {
        $notif = new Notification();
        $notif->setUser($user);
        $notif->setMessage($message);
        $notif->setType($type);
        $notif->setCreatedAt(new \DateTime());
        $notif->setIsRead(false);

        $this->em->persist($notif);
        $this->em->flush();
    }

    private function sendEmailNotification(User $user, string $message, string $type, ?string $emailSubject = null): void
    {
        if ($this->isMailerDisabled()) {
            throw new \RuntimeException('Le transport e-mail FinTrust est desactive. Configurez MAILER_DSN avec un SMTP reel.');
        }

        $subject = trim((string) $emailSubject);
        if ($subject === '') {
            $subject = $this->buildSubject($type);
        }

        $email = (new TemplatedEmail())
            ->from(new Address($this->fintrustMailerFrom, 'FinTrust'))
            ->to(new Address($user->getEmail(), $user->getFullName()))
            ->subject($subject)
            ->htmlTemplate('emails/admin_notification.html.twig')
            ->context([
                'user' => $user,
                'message' => $message,
                'type' => $type,
                'typeLabel' => $this->buildTypeLabel($type),
                'emailSubject' => $subject,
            ]);

        $this->mailer->send($email);
    }

    private function isMailerDisabled(): bool
    {
        $dsn = strtolower(trim($this->fintrustMailerDsn));

        return $dsn === '' || $dsn === 'null://null';
    }

    private function buildSubject(string $type): string
    {
        return match (strtoupper($type)) {
            'SUCCESS' => 'FinTrust - Mise a jour positive sur votre compte',
            'WARNING' => 'FinTrust - Action requise sur votre compte',
            'ERROR' => 'FinTrust - Alerte importante sur votre compte',
            default => 'FinTrust - Nouvelle notification de votre espace client',
        };
    }

    private function buildTypeLabel(string $type): string
    {
        return match (strtoupper($type)) {
            'SUCCESS' => 'Succes',
            'WARNING' => 'Avertissement',
            'ERROR' => 'Alerte',
            default => 'Information',
        };
    }
}
