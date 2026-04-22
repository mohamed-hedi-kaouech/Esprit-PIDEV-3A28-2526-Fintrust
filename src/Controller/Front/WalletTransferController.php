<?php

namespace App\Controller\Front;

use App\Dto\WalletTransferData;
use App\Entity\User\User;
use App\Entity\Wallet\Wallet;
use App\Exception\WalletTransferException;
use App\Form\Front\WalletTransferType;
use App\Security\KycAccessChecker;
use App\Security\RiskAccessChecker;
use App\Service\KycService;
use App\Service\WalletTransferService;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_CLIENT')]
#[Route('/espace-client/wallet/transferts', name: 'front_wallet_transfer_')]
class WalletTransferController extends AbstractController
{
    public function __construct(
        private readonly KycAccessChecker $kycAccessChecker,
        private readonly RiskAccessChecker $riskAccessChecker,
        private readonly KycService $kycService,
        private readonly WalletTransferService $walletTransferService,
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

        $wallet = $this->requireWalletForUser($user);
        if ($wallet instanceof Response) {
            return $wallet;
        }

        $form = $this->createForm(WalletTransferType::class, new WalletTransferData());
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $transfer = $this->walletTransferService->transfer($user, $form->getData());

                $this->addFlash(
                    'success',
                    sprintf(
                        'Le transfert %s de %.2f %s a bien ete envoye vers %s.',
                        $transfer['reference'],
                        $transfer['amount'],
                        $wallet->getDevise(),
                        $transfer['destination_wallet_label']
                    )
                );

                return $this->redirectToRoute('front_wallet_transfer_history');
            } catch (WalletTransferException $exception) {
                $this->addFlash('error', $exception->getMessage());
            } catch (\Throwable $exception) {
                $this->logger->error('Erreur inattendue pendant un transfert wallet.', [
                    'message' => $exception->getMessage(),
                    'exception' => $exception,
                    'user_id' => $user->getId(),
                    'wallet_id' => $wallet->getIdWallet(),
                ]);

                $this->addFlash('error', 'Erreur technique temporaire: ' . $exception->getMessage());
            }
        }

        return $this->render('front/client/wallet/transfer_new.html.twig', [
            'wallet' => $wallet,
            'form' => $form,
        ]);
    }

    #[Route('', name: 'history', methods: ['GET'])]
    public function history(Request $request): Response
    {
        $user = $this->getAuthenticatedUser();

        if ($redirect = $this->guardWalletAccess($user)) {
            return $redirect;
        }

        $wallet = $this->requireWalletForUser($user);
        if ($wallet instanceof Response) {
            return $wallet;
        }

        $filters = $this->getHistoryFilters($request);
        $transfers = $this->walletTransferService->getTransferHistory($wallet, $user, $filters);

        return $this->render('front/client/wallet/transfers.html.twig', [
            'wallet' => $wallet,
            'filters' => $filters,
            'transfers' => $transfers,
        ]);
    }

    #[Route('/export/csv', name: 'export_csv', methods: ['GET'])]
    public function exportCsv(Request $request): StreamedResponse|Response
    {
        $user = $this->getAuthenticatedUser();

        if ($redirect = $this->guardWalletAccess($user)) {
            return $redirect;
        }

        $wallet = $this->requireWalletForUser($user);
        if ($wallet instanceof Response) {
            return $wallet;
        }

        $filters = $this->getHistoryFilters($request);
        $transfers = $this->walletTransferService->getTransferHistory($wallet, $user, $filters);

        $response = new StreamedResponse(function () use ($transfers, $wallet) {
            $handle = fopen('php://output', 'w');
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, ['Reference', 'Sens', 'Statut', 'Montant', 'Contrepartie', 'Libelle', 'Date', 'Devise'], ';');

            foreach ($transfers as $transfer) {
                fputcsv($handle, [
                    $transfer['reference'],
                    $transfer['direction'] === 'received' ? 'Recu' : 'Envoye',
                    $transfer['status'],
                    number_format((float) $transfer['amount'], 2, '.', ''),
                    $transfer['counterparty'],
                    $transfer['label'] ?? '',
                    $transfer['date']->format('d/m/Y H:i'),
                    $wallet->getDevise(),
                ], ';');
            }

            fclose($handle);
        });

        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="wallet_transfers_' . date('Ymd_His') . '.csv"');

        return $response;
    }

    #[Route('/export/pdf', name: 'export_pdf', methods: ['GET'])]
    public function exportPdf(Request $request): Response
    {
        $user = $this->getAuthenticatedUser();

        if ($redirect = $this->guardWalletAccess($user)) {
            return $redirect;
        }

        $wallet = $this->requireWalletForUser($user);
        if ($wallet instanceof Response) {
            return $wallet;
        }

        $filters = $this->getHistoryFilters($request);

        return $this->render('front/client/wallet/transfers_export_pdf.html.twig', [
            'wallet' => $wallet,
            'transfers' => $this->walletTransferService->getTransferHistory($wallet, $user, $filters),
            'filters' => $filters,
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

    private function requireWalletForUser(User $user): Wallet|Response
    {
        $wallet = $this->walletTransferService->findWalletForUser($user);

        if ($wallet instanceof Wallet) {
            return $wallet;
        }

        if ($user->getKycStatus() === User::KYC_APPROUVE) {
            $this->kycService->synchronizeApprovedUserWallet($user);
            $wallet = $this->walletTransferService->findWalletForUser($user);

            if ($wallet instanceof Wallet) {
                return $wallet;
            }
        }

        $this->addFlash('info', 'Aucun wallet n est encore disponible sur votre compte.');

        return $this->redirectToRoute('front_wallet_dashboard');
    }

    /**
     * @return array{direction:string,query:string,status:string,date_from:string,date_to:string}
     */
    private function getHistoryFilters(Request $request): array
    {
        $direction = trim((string) $request->query->get('direction', 'all'));
        if (!in_array($direction, ['all', 'sent', 'received'], true)) {
            $direction = 'all';
        }

        return [
            'direction' => $direction,
            'query' => trim((string) $request->query->get('query', '')),
            'status' => trim((string) $request->query->get('status', '')),
            'date_from' => trim((string) $request->query->get('date_from', '')),
            'date_to' => trim((string) $request->query->get('date_to', '')),
        ];
    }
}
