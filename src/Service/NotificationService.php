<?php

namespace App\Service;

use App\Entity\User\Client\Notification;
use App\Entity\User\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Service - Notifications internes.
 *
 * Cree des notifications stockees en base de donnees.
 * Extensible pour integrer Symfony Mailer (email) ou Notifier (SMS/push).
 */
class NotificationService
{
    public function __construct(private readonly EntityManagerInterface $em) {}

    /**
     * Cree une notification interne pour un utilisateur.
     *
     * @param string $type INFO | SUCCESS | WARNING | ERROR
     */
    public function notify(User $user, string $message, string $type = 'INFO'): void
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
}
