<?php

namespace App\Controller\Front;

use App\Entity\User\User;
use App\Entity\Wallet\Wallet;
use App\Security\KycAccessChecker;
use App\Security\RiskAccessChecker;
use App\Service\KycService;
use App\Service\WalletTransferService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_CLIENT')]
class MerchantQrPaymentPageController extends AbstractController
{
    public function __construct(
        private readonly KycAccessChecker $kycAccessChecker,
        private readonly RiskAccessChecker $riskAccessChecker,
        private readonly KycService $kycService,
        private readonly WalletTransferService $walletTransferService,
    ) {
    }

    #[Route('/espace-client/wallet/paiement-qr', name: 'front_wallet_merchant_qr_payment', methods: ['GET'])]
    public function __invoke(): Response
    {
        $user = $this->getAuthenticatedUser();

        if ($redirect = $this->guardWalletAccess($user)) {
            return $redirect;
        }

        return $this->render('front/client/wallet/merchant_qr_payment.html.twig', [
            'wallet' => $this->requireWalletForUser($user),
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

    private function requireWalletForUser(User $user): ?Wallet
    {
        $wallet = $this->walletTransferService->findWalletForUser($user);
        if ($wallet instanceof Wallet) {
            return $wallet;
        }

        if ($user->getKycStatus() === User::KYC_APPROUVE) {
            $this->kycService->synchronizeApprovedUserWallet($user);

            return $this->walletTransferService->findWalletForUser($user);
        }

        return null;
    }
}
