<?php

namespace App\Controller\Front;

use App\Entity\User\User;
use App\Entity\Wallet\Wallet;
use App\Security\KycAccessChecker;
use App\Security\RiskAccessChecker;
use App\Service\KycService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_CLIENT')]
class VoiceAssistantPageController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly KycAccessChecker $kycAccessChecker,
        private readonly RiskAccessChecker $riskAccessChecker,
        private readonly KycService $kycService,
    ) {
    }

    #[Route('/espace-client/wallet/assistant-vocal', name: 'front_wallet_voice_assistant', methods: ['GET'])]
    public function __invoke(): Response
    {
        $user = $this->getAuthenticatedUser();

        if ($redirect = $this->guardWalletAccess($user)) {
            return $redirect;
        }

        return $this->render('front/client/wallet/voice_assistant.html.twig', [
            'wallet' => $this->findWalletForUser($user),
        ]);
    }

    private function getAuthenticatedUser(): User
    {
        /** @var User $user */
        $user = $this->getUser();

        return $user;
    }

    private function guardWalletAccess(User $user): ?Response
    {
        if ($redirect = $this->kycAccessChecker->check($user)) {
            $this->addFlash('warning', 'Votre KYC doit etre approuve pour acceder a votre espace wallet.');

            return $redirect;
        }

        if ($redirect = $this->riskAccessChecker->checkSensitiveModule($user)) {
            $this->addFlash('warning', 'Votre niveau de risque est critique. Les actions wallet sont temporairement restreintes.');

            return $redirect;
        }

        return null;
    }

    private function findWalletForUser(User $user): ?Wallet
    {
        /** @var Wallet|null $wallet */
        $wallet = $this->entityManager->getRepository(Wallet::class)
            ->createQueryBuilder('w')
            ->andWhere('w.user = :user OR w.idUser = :userId')
            ->setParameter('user', $user)
            ->setParameter('userId', $user->getId())
            ->orderBy('w.dateCreation', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        if (!$wallet instanceof Wallet && $user->getKycStatus() === User::KYC_APPROUVE) {
            $this->kycService->synchronizeApprovedUserWallet($user);

            /** @var Wallet|null $wallet */
            $wallet = $this->entityManager->getRepository(Wallet::class)
                ->createQueryBuilder('w')
                ->andWhere('w.user = :user OR w.idUser = :userId')
                ->setParameter('user', $user)
                ->setParameter('userId', $user->getId())
                ->orderBy('w.dateCreation', 'DESC')
                ->setMaxResults(1)
                ->getQuery()
                ->getOneOrNullResult();
        }

        return $wallet;
    }
}
