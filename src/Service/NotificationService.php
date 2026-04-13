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

    public function notifyTransferSent(User $user, string $reference, float $amount, string $devise, string $recipientLabel, ?string $label = null): void
    {
        $message = sprintf(
            'Transfert envoye | Ref: %s | Vous avez envoye %.2f %s vers %s.',
            $reference,
            $amount,
            $devise,
            $recipientLabel
        );

        if ($label !== null && trim($label) !== '') {
            $message .= ' Libelle: ' . trim($label) . '.';
        }

        $message .= ' Statut: VALIDE.';

        $this->notify($user, $message, 'SUCCESS');
    }

    public function notifyTransferReceived(User $user, string $reference, float $amount, string $devise, string $senderLabel, ?string $label = null): void
    {
        $message = sprintf(
            'Transfert recu | Ref: %s | Vous avez recu %.2f %s de %s.',
            $reference,
            $amount,
            $devise,
            $senderLabel
        );

        if ($label !== null && trim($label) !== '') {
            $message .= ' Libelle: ' . trim($label) . '.';
        }

        $message .= ' Statut: VALIDE.';

        $this->notify($user, $message, 'SUCCESS');
    }

    public function notifyChequeRequested(User $user, string $chequeNumber, float $amount, ?string $beneficiary = null): void
    {
        $message = sprintf(
            'Demande de chequier | Numero: %s | Montant: %.2f TND | Statut: EN_ATTENTE.',
            $chequeNumber,
            $amount
        );

        if ($beneficiary !== null && trim($beneficiary) !== '') {
            $message .= ' Beneficiaire: ' . trim($beneficiary) . '.';
        }

        $this->notify($user, $message, 'INFO');
    }

    public function notifyChequeApproved(User $user, string $chequeNumber): void
    {
        $this->notify(
            $user,
            sprintf('Demande de chequier approuvee | Numero: %s | Statut: ACCEPTE.', $chequeNumber),
            'SUCCESS'
        );
    }

    public function notifyChequeRejected(User $user, string $chequeNumber, ?string $reason = null): void
    {
        $message = sprintf('Demande de chequier refusee | Numero: %s | Statut: REFUSE.', $chequeNumber);

        if ($reason !== null && trim($reason) !== '') {
            $message .= ' Motif: ' . trim($reason) . '.';
        }

        $this->notify($user, $message, 'ERROR');
    }

    public function notifyChequeDelivered(User $user, string $chequeNumber): void
    {
        $this->notify(
            $user,
            sprintf('Chequier livre | Numero: %s | Statut: LIVRE.', $chequeNumber),
            'SUCCESS'
        );
    }

    public function notifyWalletStatusChanged(User $user, string $walletStatus): void
    {
        $type = match (mb_strtolower($walletStatus)) {
            'actif' => 'SUCCESS',
            'suspendu' => 'WARNING',
            'bloque' => 'ERROR',
            default => 'INFO',
        };

        $this->notify(
            $user,
            sprintf('Statut wallet mis a jour | Nouveau statut: %s.', mb_strtoupper($walletStatus)),
            $type
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
