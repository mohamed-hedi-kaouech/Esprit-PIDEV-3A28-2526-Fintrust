<?php

namespace App\Service;

use App\Entity\Product\ProductSubscription;
use App\Entity\User\Client\Kyc;
use App\Entity\User\Client\KycFile;
use App\Entity\User\Client\Notification;
use App\Entity\User\User;
use App\Entity\Wallet\Wallet;
use App\Exception\UserDeletionException;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class UserService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserRepository $userRepository,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly QrCodeService $qrCodeService,
        private readonly BehavioralProfileService $behavioralProfileService,
        private readonly NotificationService $notificationService,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {}

    public function registerClient(User $user, string $plainPassword): void
    {
        $user->setRole(User::ROLE_CLIENT);
        $user->setStatus(User::STATUS_EN_ATTENTE);
        $user->setCreatedAt(new \DateTime());
        $user->setPassword($this->passwordHasher->hashPassword($user, $plainPassword));
        $user->setQrToken($this->qrCodeService->generateToken());
        $user->setIsVerified(false);

        $this->refreshEmailVerificationCode($user, false);

        $this->em->persist($user);
        $this->em->flush();
        $this->behavioralProfileService->refreshUserBehavior($user);
    }

    public function createClientByAdmin(User $user, string $plainPassword): void
    {
        $user->setRole(User::ROLE_CLIENT);
        $user->setCreatedAt(new \DateTime());
        $user->setPassword($this->passwordHasher->hashPassword($user, $plainPassword));
        $user->setQrToken($this->qrCodeService->generateToken());
        $user->setIsVerified(true);
        $user->setEmailVerificationCode(null);
        $user->setEmailVerificationExpiresAt(null);
        $user->setEmailVerifiedAt(new \DateTime());

        $this->em->persist($user);
        $this->em->flush();
        $this->behavioralProfileService->refreshUserBehavior($user);
    }

    public function refreshEmailVerificationCode(User $user, bool $flush = true): string
    {
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        $user->setIsVerified(false);
        $user->setEmailVerificationCode($code);
        $user->setEmailVerificationExpiresAt(new \DateTime('+10 minutes'));
        $user->setEmailVerifiedAt(null);

        if ($flush) {
            $this->em->flush();
        }

        return $code;
    }

    public function isVerificationCodeExpired(User $user): bool
    {
        return $user->isEmailVerificationExpired();
    }

    public function isVerificationCodeValid(User $user, string $submittedCode): bool
    {
        return $user->getEmailVerificationCode() !== null
            && hash_equals($user->getEmailVerificationCode(), trim($submittedCode))
            && !$this->isVerificationCodeExpired($user);
    }

    public function markEmailAsVerified(User $user): void
    {
        $user->setIsVerified(true);
        $user->setEmailVerificationCode(null);
        $user->setEmailVerificationExpiresAt(null);
        $user->setEmailVerifiedAt(new \DateTime());
        $this->em->flush();
    }

    public function activateUser(User $user): void
    {
        $user->setStatus(User::STATUS_ACTIF);
        $this->em->flush();
    }

    public function suspendUser(User $user): void
    {
        $user->setStatus(User::STATUS_SUSPENDU);
        $this->em->flush();
    }

    public function deleteUser(User $user): void
    {
        $wallet = $this->findWalletLinkedToUser($user);

        if ($wallet instanceof Wallet) {
            $walletStatus = trim((string) $wallet->getStatut());
            $message = 'Impossible de supprimer cet utilisateur car un wallet lui est deja rattache.';

            if ($walletStatus !== '') {
                $message .= sprintf(' Statut du wallet: %s.', $walletStatus);
            }

            $message .= ' Supprimez ou detachez d abord ce wallet.';

            throw new UserDeletionException($message);
        }

        /** @var ProductSubscription[] $subscriptions */
        $subscriptions = $this->em->getRepository(ProductSubscription::class)->findBy(['clientUser' => $user]);

        foreach ($subscriptions as $subscription) {
            $this->em->remove($subscription);
        }

        /** @var Kyc[] $kycRecords */
        $kycRecords = $this->em->getRepository(Kyc::class)->findBy(['user' => $user]);

        foreach ($kycRecords as $kyc) {
            foreach ($kyc->getFiles() as $file) {
                $this->deleteKycFileArtifact($file);
                $this->em->remove($file);
            }

            $this->deleteRelativeFile($kyc->getSignaturePath());
            $this->em->remove($kyc);
        }

        $user->setCurrentKycId(null);
        $this->em->remove($user);
        $this->em->flush();
    }

    private function findWalletLinkedToUser(User $user): ?Wallet
    {
        /** @var Wallet|null $wallet */
        $wallet = $this->em->getRepository(Wallet::class)->findOneBy(['user' => $user]);

        if ($wallet instanceof Wallet) {
            return $wallet;
        }

        /** @var Wallet|null $wallet */
        $wallet = $this->em->getRepository(Wallet::class)->findOneBy(['idUser' => $user->getId()]);

        return $wallet;
    }

    private function deleteKycFileArtifact(KycFile $file): void
    {
        $this->deleteRelativeFile($file->getFilePath());
    }

    private function deleteRelativeFile(?string $relativePath): void
    {
        if ($relativePath === null || $relativePath === '') {
            return;
        }

        $absolutePath = $this->projectDir . '/public/' . ltrim($relativePath, '/');

        if (is_file($absolutePath)) {
            @unlink($absolutePath);
        }
    }

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

    public function findByQrToken(string $token): ?User
    {
        return $this->userRepository->findOneBy(['qrToken' => $token]);
    }

    /**
     * @return Notification[]
     */
    public function getNotifications(User $user): array
    {
        return $this->em->getRepository(Notification::class)
            ->findBy(['user' => $user], ['createdAt' => 'DESC']);
    }
}
