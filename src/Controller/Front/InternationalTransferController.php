<?php

namespace App\Controller\Front;

use App\Dto\InternationalTransferData;
use App\Entity\User\User;
use App\Exception\InternationalTransferException;
use App\Form\Front\InternationalTransferOtpType;
use App\Form\Front\InternationalTransferType;
use App\Security\KycAccessChecker;
use App\Security\RiskAccessChecker;
use App\Service\ExchangeRateApiService;
use App\Service\InternationalTransferService;
use App\Service\KycService;
use App\Service\OtpVerificationService;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_CLIENT')]
#[Route('/espace-client/wallet/transferts-internationaux', name: 'front_wallet_international_transfer_')]
class InternationalTransferController extends AbstractController
{
    private const OTP_SESSION_KEY = 'international_transfer.pending_otp';
    private const OTP_TTL_SECONDS = 600;

    public function __construct(
        private readonly KycAccessChecker $kycAccessChecker,
        private readonly RiskAccessChecker $riskAccessChecker,
        private readonly KycService $kycService,
        private readonly InternationalTransferService $internationalTransferService,
        private readonly ExchangeRateApiService $exchangeRateApiService,
        private readonly OtpVerificationService $otpVerificationService,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/nouveau', name: 'new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $user = $this->getAuthenticatedUser();

        if ($redirect = $this->guardWalletAccess($user)) {
            return $redirect;
        }

        $wallets = $this->internationalTransferService->findWalletsForUser($user);
        if ($wallets === []) {
            $this->addFlash('info', 'Aucun wallet n est encore disponible sur votre compte.');

            return $this->redirectToRoute('front_wallet_dashboard');
        }

        $initialData = (new InternationalTransferData())
            ->setSourceWalletId($wallets[0]->getIdWallet())
            ->setSourceCurrency(mb_strtoupper($wallets[0]->getDevise()));

        $form = $this->createTransferForm($initialData);
        $form->handleRequest($request);

        $preview = null;
        $completedTransfer = null;

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                /** @var InternationalTransferData $submittedData */
                $submittedData = $form->getData();
                $this->synchronizeSourceCurrency($submittedData, $wallets);
                $otpDestination = $this->resolveOtpDestination($submittedData, $wallets, $user);

                if ($request->request->has('confirm_transfer')) {
                    $preview = $this->internationalTransferService->buildPreview($user, $submittedData);
                    $otpDispatch = $this->otpVerificationService->sendCode($user, $otpDestination);
                    $this->storePendingTransfer($request, $user, $submittedData, $preview, $otpDispatch);
                    $this->addFlash('success', (string) ($otpDispatch['user_message'] ?? 'Un code OTP a ete prepare pour confirmer le transfert international.'));

                    return $this->redirectToRoute('front_wallet_international_transfer_verify_otp');
                } else {
                    $preview = $this->internationalTransferService->buildPreview($user, $submittedData);
                }
            } catch (InternationalTransferException $exception) {
                $this->addFlash('error', $exception->getMessage());
            } catch (\Throwable $exception) {
                $this->logger->error('Erreur inattendue pendant un transfert international simule.', [
                    'message' => $exception->getMessage(),
                    'exception' => $exception,
                    'user_id' => $user->getId(),
                ]);

                $this->addFlash('error', 'Erreur technique temporaire: ' . $exception->getMessage());
            }
        } elseif ($form->isSubmitted()) {
            $this->addFlash('error', 'Le formulaire de transfert international contient des champs invalides.');
        }

        return $this->render('front/client/wallet/international_transfer_new.html.twig', [
            'form' => $form,
            'wallets' => $wallets,
            'preview' => $preview,
            'completedTransfer' => $completedTransfer,
        ]);
    }

    #[Route('/verification-otp', name: 'verify_otp', methods: ['GET', 'POST'])]
    public function verifyOtp(Request $request): Response
    {
        $user = $this->getAuthenticatedUser();

        if ($redirect = $this->guardWalletAccess($user)) {
            return $redirect;
        }

        $pendingTransfer = $this->getPendingTransfer($request, $user);
        if ($pendingTransfer === null) {
            $this->addFlash('warning', 'Aucun transfert international en attente de verification OTP.');

            return $this->redirectToRoute('front_wallet_international_transfer_new');
        }

        if ($this->isPendingTransferExpired($pendingTransfer)) {
            $this->clearPendingTransfer($request);
            $this->addFlash('error', 'Le code OTP a expire. Veuillez relancer le transfert international.');

            return $this->redirectToRoute('front_wallet_international_transfer_new');
        }

        $form = $this->createForm(InternationalTransferOtpType::class);
        $form->handleRequest($request);
        $completedTransfer = null;

        if ($form->isSubmitted() && $form->isValid()) {
            $otpCode = (string) $form->get('otp_code')->getData();
            $destinationPhone = is_string($pendingTransfer['phone_number'] ?? null) ? $pendingTransfer['phone_number'] : null;

            try {
                if (!$this->otpVerificationService->verifyCode($user, $otpCode, $destinationPhone)) {
                    $this->addFlash('error', 'Le code OTP est incorrect ou n est plus valide. Veuillez reessayer.');
                } else {
                    $transferData = $this->deserializeTransferData($pendingTransfer['transfer_data'] ?? []);
                    $completedTransfer = $this->internationalTransferService->execute($user, $transferData);
                    $this->clearPendingTransfer($request);
                    $this->addFlash('success', sprintf('Le transfert international %s a ete confirme et enregistre avec succes.', $completedTransfer['reference_code']));
                }
            } catch (InternationalTransferException $exception) {
                $this->addFlash('error', $exception->getMessage());
            } catch (\Throwable $exception) {
                $this->logger->error('Erreur inattendue pendant la verification OTP du transfert international.', [
                    'message' => $exception->getMessage(),
                    'exception' => $exception,
                    'user_id' => $user->getId(),
                ]);

                $this->addFlash('error', 'Erreur technique temporaire pendant la verification OTP. Veuillez reessayer.');
            }
        } elseif ($form->isSubmitted()) {
            $this->addFlash('error', 'Le code OTP saisi est invalide.');
        }

        return $this->render('front/client/wallet/international_transfer_verify_otp.html.twig', [
            'form' => $form,
            'preview' => $pendingTransfer['preview'] ?? null,
            'maskedPhone' => $this->otpVerificationService->getMaskedPhone($user, $destinationPhone),
            'expiresAt' => isset($pendingTransfer['expires_at']) ? new \DateTimeImmutable((string) $pendingTransfer['expires_at']) : null,
            'otpMode' => $pendingTransfer['otp_mode'] ?? 'live',
            'completedTransfer' => $completedTransfer,
        ]);
    }

    #[Route('/verification-otp/renvoyer', name: 'resend_otp', methods: ['POST'])]
    public function resendOtp(Request $request): Response
    {
        $user = $this->getAuthenticatedUser();
        $pendingTransfer = $this->getPendingTransfer($request, $user);

        if ($pendingTransfer === null) {
            $this->addFlash('warning', 'Aucun transfert international en attente de verification OTP.');

            return $this->redirectToRoute('front_wallet_international_transfer_new');
        }

        try {
            $destinationPhone = is_string($pendingTransfer['phone_number'] ?? null) ? $pendingTransfer['phone_number'] : null;
            $otpDispatch = $this->otpVerificationService->sendCode($user, $destinationPhone);
            $pendingTransfer['expires_at'] = (new \DateTimeImmutable('+' . self::OTP_TTL_SECONDS . ' seconds'))->format(\DateTimeInterface::ATOM);
            $pendingTransfer['otp_mode'] = $otpDispatch['mode'] ?? 'live';
            $pendingTransfer['phone_number'] = $otpDispatch['phone_number'] ?? $destinationPhone;
            $request->getSession()->set(self::OTP_SESSION_KEY, $pendingTransfer);
            $this->addFlash('success', (string) ($otpDispatch['user_message'] ?? 'Un nouveau code OTP a ete envoye.'));
        } catch (InternationalTransferException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }

        return $this->redirectToRoute('front_wallet_international_transfer_verify_otp');
    }

    private function createTransferForm(InternationalTransferData $data): FormInterface
    {
        /** @var User $user */
        $user = $this->getUser();

        return $this->createForm(InternationalTransferType::class, $data, [
            'wallet_choices' => $this->internationalTransferService->getWalletChoicesForUser($user),
            'currency_choices' => $this->exchangeRateApiService->getSupportedCurrencyChoices(),
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

    /**
     * @param array<string, mixed> $preview
     * @param array<string, mixed> $otpDispatch
     */
    private function storePendingTransfer(Request $request, User $user, InternationalTransferData $data, array $preview, array $otpDispatch): void
    {
        $request->getSession()->set(self::OTP_SESSION_KEY, [
            'user_id' => $user->getId(),
            'created_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'expires_at' => (new \DateTimeImmutable('+' . self::OTP_TTL_SECONDS . ' seconds'))->format(\DateTimeInterface::ATOM),
            'transfer_data' => $this->serializeTransferData($data),
            'preview' => $preview,
            'otp_mode' => $otpDispatch['mode'] ?? 'live',
            'phone_number' => $otpDispatch['phone_number'] ?? null,
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function getPendingTransfer(Request $request, User $user): ?array
    {
        $pendingTransfer = $request->getSession()->get(self::OTP_SESSION_KEY);
        if (!is_array($pendingTransfer)) {
            return null;
        }

        if ((int) ($pendingTransfer['user_id'] ?? 0) !== $user->getId()) {
            $this->clearPendingTransfer($request);

            return null;
        }

        return $pendingTransfer;
    }

    /**
     * @param array<string, mixed> $pendingTransfer
     */
    private function isPendingTransferExpired(array $pendingTransfer): bool
    {
        $expiresAt = $pendingTransfer['expires_at'] ?? null;
        if (!is_string($expiresAt) || $expiresAt === '') {
            return true;
        }

        return new \DateTimeImmutable($expiresAt) < new \DateTimeImmutable();
    }

    private function clearPendingTransfer(Request $request): void
    {
        $request->getSession()->remove(self::OTP_SESSION_KEY);
    }

    /**
     * @param array<int, \App\Entity\Wallet\Wallet> $wallets
     */
    private function resolveOtpDestination(InternationalTransferData $data, array $wallets, User $user): ?string
    {
        $selectedWalletId = (int) ($data->getSourceWalletId() ?? 0);

        foreach ($wallets as $wallet) {
            if ($wallet->getIdWallet() === $selectedWalletId) {
                $walletPhone = trim((string) $wallet->getTelephone());

                return $walletPhone !== '' ? $walletPhone : $user->getNumTel();
            }
        }

        return $user->getNumTel();
    }

    /**
     * @param array<int, \App\Entity\Wallet\Wallet> $wallets
     */
    private function synchronizeSourceCurrency(InternationalTransferData $data, array $wallets): void
    {
        $selectedWalletId = (int) ($data->getSourceWalletId() ?? 0);

        foreach ($wallets as $wallet) {
            if ($wallet->getIdWallet() === $selectedWalletId) {
                $data->setSourceCurrency(mb_strtoupper($wallet->getDevise()));

                return;
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeTransferData(InternationalTransferData $data): array
    {
        return [
            'sourceWalletId' => $data->getSourceWalletId(),
            'amount' => $data->getAmount(),
            'sourceCurrency' => $data->getSourceCurrency(),
            'targetCurrency' => $data->getTargetCurrency(),
            'beneficiary' => $data->getBeneficiary(),
            'reference' => $data->getReference(),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function deserializeTransferData(array $payload): InternationalTransferData
    {
        return (new InternationalTransferData())
            ->setSourceWalletId(isset($payload['sourceWalletId']) ? (int) $payload['sourceWalletId'] : null)
            ->setAmount(isset($payload['amount']) ? (float) $payload['amount'] : null)
            ->setSourceCurrency(isset($payload['sourceCurrency']) ? (string) $payload['sourceCurrency'] : null)
            ->setTargetCurrency(isset($payload['targetCurrency']) ? (string) $payload['targetCurrency'] : null)
            ->setBeneficiary(isset($payload['beneficiary']) ? (string) $payload['beneficiary'] : null)
            ->setReference(isset($payload['reference']) ? (string) $payload['reference'] : null);
    }
}
