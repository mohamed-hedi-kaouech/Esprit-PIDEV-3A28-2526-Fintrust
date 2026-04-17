<?php

namespace App\Controller\Front;

use App\Dto\InternationalTransferData;
use App\Entity\User\User;
use App\Exception\InternationalTransferException;
use App\Form\Front\InternationalTransferType;
use App\Security\KycAccessChecker;
use App\Security\RiskAccessChecker;
use App\Service\ExchangeRateApiService;
use App\Service\InternationalTransferService;
use App\Service\KycService;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_CLIENT')]
#[Route('/espace-client/wallet/transferts-internationaux', name: 'front_wallet_international_transfer_')]
class InternationalTransferController extends AbstractController
{
    public function __construct(
        private readonly KycAccessChecker $kycAccessChecker,
        private readonly RiskAccessChecker $riskAccessChecker,
        private readonly KycService $kycService,
        private readonly InternationalTransferService $internationalTransferService,
        private readonly ExchangeRateApiService $exchangeRateApiService,
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
                $submittedData = $form->getData();

                if ($request->request->has('confirm_transfer')) {
                    $completedTransfer = $this->internationalTransferService->execute($user, $submittedData);
                    $this->addFlash(
                        'success',
                        sprintf(
                            'Le transfert international %s a ete simule avec succes.',
                            $completedTransfer['reference_code']
                        )
                    );

                    $form = $this->createTransferForm($initialData);
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

    private function createTransferForm(InternationalTransferData $data): \Symfony\Component\Form\FormInterface
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
}
