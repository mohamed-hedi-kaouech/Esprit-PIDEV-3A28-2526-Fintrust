<?php

namespace App\Service;

use App\Entity\User\Client\Notification;
use App\Entity\User\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Service métier — Gestion des utilisateurs.
 *
 * Centralise la logique d'inscription, d'activation, de suspension,
 * de suppression et de mise à jour du profil.
 */
class UserService
{
    public function __construct(
        private readonly EntityManagerInterface      $em,
        private readonly UserRepository              $userRepository,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly QrCodeService               $qrCodeService,
        private readonly BehavioralProfileService    $behavioralProfileService,
        private readonly NotificationService         $notificationService,
    ) {}

    /**
     * Inscrit un nouvel utilisateur CLIENT.
     * Hash le mot de passe, génère un QR token, définit le statut initial.
     */
    public function registerClient(User $user, string $plainPassword): void
    {
        $user->setRole(User::ROLE_CLIENT);
        $user->setStatus(User::STATUS_EN_ATTENTE);
        $user->setCreatedAt(new \DateTime());
        $user->setPassword($this->passwordHasher->hashPassword($user, $plainPassword));
        $user->setQrToken($this->qrCodeService->generateToken());

        $this->em->persist($user);
        $this->em->flush();
        $this->behavioralProfileService->refreshUserBehavior($user);
    }

    /**
     * Crée un client depuis le back-office admin.
     * Le statut/KYC sont définis dans le formulaire admin.
     */
    public function createClientByAdmin(User $user, string $plainPassword): void
    {
        $user->setRole(User::ROLE_CLIENT);
        $user->setCreatedAt(new \DateTime());
        $user->setPassword($this->passwordHasher->hashPassword($user, $plainPassword));
        $user->setQrToken($this->qrCodeService->generateToken());

        $this->em->persist($user);
        $this->em->flush();
        $this->behavioralProfileService->refreshUserBehavior($user);
    }

    /**
     * Active le compte d'un utilisateur (statut → ACTIF).
     */
    public function activateUser(User $user): void
    {
        $user->setStatus(User::STATUS_ACTIF);
        $this->em->flush();
    }

    /**
     * Suspend le compte d'un utilisateur (statut → SUSPENDU).
     */
    public function suspendUser(User $user): void
    {
        $user->setStatus(User::STATUS_SUSPENDU);
        $this->em->flush();
    }

    /**
     * Supprime définitivement un utilisateur.
     */
    public function deleteUser(User $user): void
    {
        $this->em->remove($user);
        $this->em->flush();
    }

    /**
     * Persiste les modifications du profil d'un utilisateur.
     */
    public function updateProfile(User $user, ?string $plainPassword = null): void
    {
        $previousRiskLevel = $user->getRiskLevel();

        if ($plainPassword) {
            $user->setPassword($this->passwordHasher->hashPassword($user, $plainPassword));
        }

        $this->em->flush();
        $this->behavioralProfileService->refreshUserBehavior($user);

        if (
            $user->getRiskLevel() !== $previousRiskLevel
            && in_array($user->getRiskLevel(), [User::RISK_HIGH, User::RISK_CRITICAL], true)
        ) {
            $this->notificationService->notifyRiskEscalation($user, $user->getRiskLevel());
        }
    }

    /**
     * Trouve un utilisateur par son QR token unique.
     */
    public function findByQrToken(string $token): ?User
    {
        return $this->userRepository->findOneBy(['qrToken' => $token]);
    }

    /**
     * Retourne les notifications d'un utilisateur, triées par date décroissante.
     *
     * @return Notification[]
     */
    public function getNotifications(User $user): array
    {
        return $this->em->getRepository(Notification::class)
            ->findBy(['user' => $user], ['createdAt' => 'DESC']);
    }
}
