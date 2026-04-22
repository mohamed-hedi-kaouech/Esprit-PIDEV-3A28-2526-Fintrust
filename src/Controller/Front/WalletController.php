<?php

namespace App\Controller\Front;

use App\Entity\User\Client\Notification;
use App\Entity\User\User;
use App\Entity\Wallet\Cheque;
use App\Entity\Wallet\Transaction;
use App\Entity\Wallet\Wallet;
use App\Form\Front\ChequeRequestType;
use App\Form\Front\WalletTransactionType;
use App\Security\KycAccessChecker;
use App\Security\RiskAccessChecker;
use App\Service\KycService;
use App\Service\NotificationService;
use App\Service\UserService;
use App\Service\WalletAuditService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_CLIENT')]
#[Route('/espace-client/wallet', name: 'front_wallet_')]
class WalletController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly KycAccessChecker $kycAccessChecker,
        private readonly RiskAccessChecker $riskAccessChecker,
        private readonly KycService $kycService,
        private readonly UserService $userService,
        private readonly NotificationService $notificationService,
        private readonly WalletAuditService $walletAuditService,
    ) {
    }

    #[Route('', name: 'dashboard', methods: ['GET'])]
    public function dashboard(): Response
    {
        $user = $this->getAuthenticatedUser();

        if ($redirect = $this->guardWalletAccess($user)) {
            return $redirect;
        }

        $wallet = $this->findWalletForUser($user);

        if (!$wallet instanceof Wallet) {
            return $this->render('front/client/wallet/dashboard.html.twig', [
                'wallet' => null,
                'transactionCount' => 0,
                'chequeCount' => 0,
                'latestTransactions' => [],
                'latestCheques' => [],
                'transferNotifications' => [],
                'transferNotificationCount' => 0,
                'walletNotifications' => [],
                'walletStats' => $this->buildWalletStats(null, []),
                'walletChart' => ['labels' => [], 'values' => []],
                'auditEntries' => [],
            ]);
        }

        $latestTransactions = $this->getLatestTransactions($wallet, 5);
        $latestCheques = $this->getLatestCheques($wallet, 5);

        return $this->render('front/client/wallet/dashboard.html.twig', [
            'wallet' => $wallet,
            'transactionCount' => $this->countTransactionsForWallet($wallet),
            'chequeCount' => $this->countChequesForWallet($wallet),
            'latestTransactions' => $latestTransactions,
            'latestCheques' => $latestCheques,
            'transferNotifications' => $this->getTransferNotificationsForUser($user, 5),
            'transferNotificationCount' => $this->countUnreadTransferNotificationsForUser($user),
            'walletNotifications' => $this->getWalletNotificationsForUser($user, 6),
            'walletStats' => $this->buildWalletStats($wallet, $latestCheques),
            'walletChart' => $this->buildTransactionChartData($wallet),
            'auditEntries' => $this->walletAuditService->getRecentEntries(6, $user->getId()),
        ]);
    }

    #[Route('/details', name: 'show', methods: ['GET'])]
    public function show(): Response
    {
        $user = $this->getAuthenticatedUser();

        if ($redirect = $this->guardWalletAccess($user)) {
            return $redirect;
        }

        $wallet = $this->requireWalletForUser($user);
        if ($wallet instanceof Response) {
            return $wallet;
        }

        $transactions = $this->getLatestTransactions($wallet, 20);
        $cheques = $this->getLatestCheques($wallet, 20);

        return $this->render('front/client/wallet/show.html.twig', [
            'wallet' => $wallet,
            'transactions' => $transactions,
            'cheques' => $cheques,
            'transactionCount' => $this->countTransactionsForWallet($wallet),
            'chequeCount' => $this->countChequesForWallet($wallet),
            'transferNotifications' => $this->getTransferNotificationsForUser($user, 8),
            'transferNotificationCount' => $this->countUnreadTransferNotificationsForUser($user),
            'walletNotifications' => $this->getWalletNotificationsForUser($user, 8),
            'walletStats' => $this->buildWalletStats($wallet, $cheques),
            'auditEntries' => $this->walletAuditService->getRecentEntries(10, $user->getId()),
        ]);
    }

    #[Route('/transactions', name: 'transactions', methods: ['GET'])]
    public function transactions(Request $request): Response
    {
        $user = $this->getAuthenticatedUser();

        if ($redirect = $this->guardWalletAccess($user)) {
            return $redirect;
        }

        $wallet = $this->requireWalletForUser($user);
        if ($wallet instanceof Response) {
            return $wallet;
        }

        $filters = [
            'type' => trim((string) $request->query->get('type', '')),
            'date_from' => trim((string) $request->query->get('date_from', '')),
            'date_to' => trim((string) $request->query->get('date_to', '')),
        ];

        $page = max(1, (int) $request->query->get('page', 1));
        $perPage = 12;

        $qb = $this->createTransactionQueryBuilder($wallet, $filters)
            ->orderBy('t.dateTransaction', 'DESC');

        $countQb = clone $qb;
        $total = (int) $countQb
            ->select('COUNT(t.idTransaction)')
            ->resetDQLPart('orderBy')
            ->getQuery()
            ->getSingleScalarResult();

        $pages = max(1, (int) ceil($total / $perPage));
        $page = min($page, $pages);

        /** @var Transaction[] $transactions */
        $transactions = $qb
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage)
            ->getQuery()
            ->getResult();

        return $this->render('front/client/wallet/transactions.html.twig', [
            'wallet' => $wallet,
            'transactions' => $transactions,
            'filters' => $filters,
            'pagination' => [
                'page' => $page,
                'pages' => $pages,
                'total' => $total,
                'perPage' => $perPage,
            ],
        ]);
    }

    #[Route('/transactions/export/csv', name: 'transactions_export_csv', methods: ['GET'])]
    public function exportTransactionsCsv(Request $request): StreamedResponse|Response
    {
        $user = $this->getAuthenticatedUser();
        if ($redirect = $this->guardWalletAccess($user)) {
            return $redirect;
        }

        $wallet = $this->requireWalletForUser($user);
        if ($wallet instanceof Response) {
            return $wallet;
        }

        $filters = [
            'type' => trim((string) $request->query->get('type', '')),
            'date_from' => trim((string) $request->query->get('date_from', '')),
            'date_to' => trim((string) $request->query->get('date_to', '')),
        ];

        /** @var Transaction[] $transactions */
        $transactions = $this->createTransactionQueryBuilder($wallet, $filters)
            ->orderBy('t.dateTransaction', 'DESC')
            ->getQuery()
            ->getResult();

        $response = new StreamedResponse(function () use ($transactions, $wallet) {
            $handle = fopen('php://output', 'w');
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, ['ID', 'Type', 'Montant', 'Description', 'Date', 'Devise'], ';');

            foreach ($transactions as $transaction) {
                fputcsv($handle, [
                    $transaction->getIdTransaction(),
                    $transaction->getType(),
                    number_format($transaction->getMontant(), 2, '.', ''),
                    $transaction->getDescription() ?? '',
                    $transaction->getDateTransaction()->format('d/m/Y H:i'),
                    $wallet->getDevise(),
                ], ';');
            }

            fclose($handle);
        });

        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="wallet_transactions_' . date('Ymd_His') . '.csv"');

        return $response;
    }

    #[Route('/transactions/export/pdf', name: 'transactions_export_pdf', methods: ['GET'])]
    public function exportTransactionsPdf(Request $request): Response
    {
        $user = $this->getAuthenticatedUser();
        if ($redirect = $this->guardWalletAccess($user)) {
            return $redirect;
        }

        $wallet = $this->requireWalletForUser($user);
        if ($wallet instanceof Response) {
            return $wallet;
        }

        $filters = [
            'type' => trim((string) $request->query->get('type', '')),
            'date_from' => trim((string) $request->query->get('date_from', '')),
            'date_to' => trim((string) $request->query->get('date_to', '')),
        ];

        /** @var Transaction[] $transactions */
        $transactions = $this->createTransactionQueryBuilder($wallet, $filters)
            ->orderBy('t.dateTransaction', 'DESC')
            ->getQuery()
            ->getResult();

        return $this->render('front/client/wallet/transactions_export_pdf.html.twig', [
            'wallet' => $wallet,
            'transactions' => $transactions,
        ]);
    }

    #[Route('/transactions/nouvelle', name: 'transaction_new', methods: ['GET', 'POST'])]
    public function newTransaction(Request $request): Response
    {
        $user = $this->getAuthenticatedUser();

        if ($redirect = $this->guardWalletAccess($user)) {
            return $redirect;
        }

        $wallet = $this->requireWalletForUser($user);
        if ($wallet instanceof Response) {
            return $wallet;
        }

        if ($wallet->getEstBloque()) {
            $this->addFlash('error', 'Votre wallet est bloque. Impossible d effectuer une transaction pour le moment.');

            return $this->redirectToRoute('front_wallet_transactions');
        }

        $transaction = new Transaction();
        $form = $this->createForm(WalletTransactionType::class, $transaction);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $type = mb_strtolower(trim($transaction->getType()));
            $montant = round($transaction->getMontant(), 2);
            $soldeActuel = round((float) $wallet->getSolde(), 2);

            if (!in_array($type, ['depot', 'retrait'], true)) {
                $form->get('type')->addError(new FormError('Le type de transaction selectionne est invalide.'));
            }

            if ($type !== 'depot' && $montant > $soldeActuel) {
                $form->get('montant')->addError(new FormError('Solde insuffisant pour effectuer cette operation.'));
            }

            if ($form->isValid()) {
                $nouveauSolde = $type === 'depot'
                    ? $soldeActuel + $montant
                    : $soldeActuel - $montant;

                $description = trim((string) $transaction->getDescription());

                $transaction
                    ->setType($type)
                    ->setMontant($montant)
                    ->setDescription($description !== '' ? $description : null)
                    ->setWallet($wallet)
                    ->setIdWallet($wallet->getIdWallet())
                    ->setDateTransaction(new \DateTime());

                $wallet->setSolde(number_format($nouveauSolde, 2, '.', ''));

                $this->entityManager->persist($transaction);
                $this->entityManager->flush();

                $this->walletAuditService->log('wallet.transaction.created', [
                    'user_id' => $user->getId(),
                    'wallet_id' => $wallet->getIdWallet(),
                    'transaction_id' => $transaction->getIdTransaction(),
                    'type' => $type,
                    'amount' => $montant,
                ]);

                $this->addFlash('success', 'La transaction a ete enregistree avec succes.');

                return $this->redirectToRoute('front_wallet_transactions');
            }
        }

        return $this->render('front/client/wallet/transaction_new.html.twig', [
            'wallet' => $wallet,
            'form' => $form,
        ]);
    }

    #[Route('/cheques/demande', name: 'cheque_request', methods: ['GET', 'POST'])]
    public function requestCheque(Request $request): Response
    {
        $user = $this->getAuthenticatedUser();

        if ($redirect = $this->guardWalletAccess($user)) {
            return $redirect;
        }

        $wallet = $this->requireWalletForUser($user);
        if ($wallet instanceof Response) {
            return $wallet;
        }

        if ($wallet->getEstBloque()) {
            $this->addFlash('error', 'Votre wallet est bloque. Impossible de demander un chequier pour le moment.');

            return $this->redirectToRoute('front_wallet_dashboard');
        }

        $cheque = new Cheque();
        $form = $this->createForm(ChequeRequestType::class, $cheque);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if ($this->hasDuplicatePendingCheque($wallet, $cheque)) {
                $this->addFlash('warning', 'Une demande similaire est deja en attente pour ce wallet.');

                return $this->redirectToRoute('front_wallet_cheques');
            }

            $cheque
                ->setWallet($wallet)
                ->setIdWallet($wallet->getIdWallet())
                ->setNumeroCheque($this->generateChequeNumber($wallet))
                ->setDateEmission(new \DateTime())
                ->setStatut('en_attente')
                ->setMotifRejet(null)
                ->setDatePresentation(null);

            $this->entityManager->persist($cheque);
            $this->entityManager->flush();

            $this->notificationService->notifyChequeRequested(
                $user,
                $cheque->getNumeroCheque(),
                $cheque->getMontant(),
                $cheque->getBeneficiaire()
            );

            $this->walletAuditService->logChequeAction(
                'wallet.cheque.requested',
                $cheque->getIdCheque(),
                $wallet->getIdWallet(),
                $user->getId(),
                $cheque->getStatut()
            );

            $this->addFlash('success', 'Votre demande de chequier a ete enregistree avec succes.');

            return $this->redirectToRoute('front_wallet_cheques');
        }

        return $this->render('front/client/wallet/cheque_request.html.twig', [
            'wallet' => $wallet,
            'form' => $form,
        ]);
    }

    #[Route('/cheques', name: 'cheques', methods: ['GET'])]
    public function cheques(): Response
    {
        $user = $this->getAuthenticatedUser();

        if ($redirect = $this->guardWalletAccess($user)) {
            return $redirect;
        }

        $wallet = $this->requireWalletForUser($user);
        if ($wallet instanceof Response) {
            return $wallet;
        }

        $cheques = $this->getLatestCheques($wallet, 50);
        $stats = $this->buildChequeStats($cheques);

        return $this->render('front/client/wallet/cheques.html.twig', [
            'wallet' => $wallet,
            'cheques' => $cheques,
            'pendingCount' => $stats['pending'],
            'acceptedCount' => $stats['accepted'],
            'deliveredCount' => $stats['delivered'],
            'refusedCount' => $stats['refused'],
        ]);
    }

    #[Route('/cheques/export/csv', name: 'cheques_export_csv', methods: ['GET'])]
    public function exportChequesCsv(): StreamedResponse|Response
    {
        $user = $this->getAuthenticatedUser();
        if ($redirect = $this->guardWalletAccess($user)) {
            return $redirect;
        }

        $wallet = $this->requireWalletForUser($user);
        if ($wallet instanceof Response) {
            return $wallet;
        }

        $cheques = $this->getLatestCheques($wallet, 200);

        $response = new StreamedResponse(function () use ($cheques, $wallet) {
            $handle = fopen('php://output', 'w');
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, ['Numero', 'Beneficiaire', 'Montant', 'Statut', 'Date emission', 'Motif rejet', 'Devise'], ';');

            foreach ($cheques as $cheque) {
                fputcsv($handle, [
                    $cheque->getNumeroCheque(),
                    $cheque->getBeneficiaire() ?? '',
                    number_format($cheque->getMontant(), 2, '.', ''),
                    $cheque->getStatut(),
                    $cheque->getDateEmission()->format('d/m/Y H:i'),
                    $cheque->getMotifRejet() ?? '',
                    $wallet->getDevise(),
                ], ';');
            }

            fclose($handle);
        });

        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="wallet_cheques_' . date('Ymd_His') . '.csv"');

        return $response;
    }

    #[Route('/cheques/export/pdf', name: 'cheques_export_pdf', methods: ['GET'])]
    public function exportChequesPdf(): Response
    {
        $user = $this->getAuthenticatedUser();
        if ($redirect = $this->guardWalletAccess($user)) {
            return $redirect;
        }

        $wallet = $this->requireWalletForUser($user);
        if ($wallet instanceof Response) {
            return $wallet;
        }

        return $this->render('front/client/wallet/cheques_export_pdf.html.twig', [
            'wallet' => $wallet,
            'cheques' => $this->getLatestCheques($wallet, 200),
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

    private function requireWalletForUser(User $user): Wallet|Response
    {
        $wallet = $this->findWalletForUser($user);

        if ($wallet instanceof Wallet) {
            return $wallet;
        }

        $this->addFlash('info', 'Aucun wallet n est encore disponible sur votre compte.');

        return $this->redirectToRoute('front_wallet_dashboard');
    }

    /**
     * @return Transaction[]
     */
    private function getLatestTransactions(Wallet $wallet, int $limit): array
    {
        /** @var Transaction[] $transactions */
        $transactions = $this->entityManager->getRepository(Transaction::class)
            ->createQueryBuilder('t')
            ->andWhere('t.idWallet = :walletId')
            ->setParameter('walletId', $wallet->getIdWallet())
            ->orderBy('t.dateTransaction', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $transactions;
    }

    /**
     * @return Cheque[]
     */
    private function getLatestCheques(Wallet $wallet, int $limit): array
    {
        /** @var Cheque[] $cheques */
        $cheques = $this->entityManager->getRepository(Cheque::class)
            ->createQueryBuilder('c')
            ->andWhere('c.idWallet = :walletId')
            ->setParameter('walletId', $wallet->getIdWallet())
            ->orderBy('c.dateEmission', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $cheques;
    }

    private function countTransactionsForWallet(Wallet $wallet): int
    {
        return (int) $this->entityManager->getRepository(Transaction::class)
            ->createQueryBuilder('t')
            ->select('COUNT(t.idTransaction)')
            ->andWhere('t.idWallet = :walletId')
            ->setParameter('walletId', $wallet->getIdWallet())
            ->getQuery()
            ->getSingleScalarResult();
    }

    private function countChequesForWallet(Wallet $wallet): int
    {
        return (int) $this->entityManager->getRepository(Cheque::class)
            ->createQueryBuilder('c')
            ->select('COUNT(c.idCheque)')
            ->andWhere('c.idWallet = :walletId')
            ->setParameter('walletId', $wallet->getIdWallet())
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @param array{type:string,date_from:string,date_to:string} $filters
     */
    private function createTransactionQueryBuilder(Wallet $wallet, array $filters): QueryBuilder
    {
        $qb = $this->entityManager->getRepository(Transaction::class)
            ->createQueryBuilder('t')
            ->andWhere('t.idWallet = :walletId')
            ->setParameter('walletId', $wallet->getIdWallet());

        if ($filters['type'] !== '') {
            $qb
                ->andWhere('LOWER(t.type) = :type')
                ->setParameter('type', mb_strtolower($filters['type']));
        }

        if ($filters['date_from'] !== '') {
            try {
                $dateFrom = new \DateTimeImmutable($filters['date_from'] . ' 00:00:00');
                $qb
                    ->andWhere('t.dateTransaction >= :dateFrom')
                    ->setParameter('dateFrom', $dateFrom);
            } catch (\Exception) {
            }
        }

        if ($filters['date_to'] !== '') {
            try {
                $dateTo = (new \DateTimeImmutable($filters['date_to'] . ' 00:00:00'))->modify('+1 day');
                $qb
                    ->andWhere('t.dateTransaction < :dateTo')
                    ->setParameter('dateTo', $dateTo);
            } catch (\Exception) {
            }
        }

        return $qb;
    }

    private function hasDuplicatePendingCheque(Wallet $wallet, Cheque $cheque): bool
    {
        $existing = $this->entityManager->getRepository(Cheque::class)
            ->createQueryBuilder('c')
            ->select('COUNT(c.idCheque)')
            ->andWhere('c.idWallet = :walletId')
            ->andWhere('LOWER(c.statut) = :statut')
            ->andWhere('LOWER(COALESCE(c.beneficiaire, :emptyValue)) = :beneficiaire')
            ->andWhere('c.montant = :montant')
            ->setParameter('walletId', $wallet->getIdWallet())
            ->setParameter('statut', 'en_attente')
            ->setParameter('emptyValue', '')
            ->setParameter('beneficiaire', mb_strtolower(trim((string) $cheque->getBeneficiaire())))
            ->setParameter('montant', $cheque->getMontant())
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $existing > 0;
    }

    private function generateChequeNumber(Wallet $wallet): string
    {
        return sprintf(
            'CHQ%s%02d',
            (new \DateTimeImmutable())->format('ymdHis'),
            random_int(10, 99)
        );
    }

    /**
     * @return Notification[]
     */
    private function getTransferNotificationsForUser(User $user, int $limit): array
    {
        $notifications = array_filter(
            $this->userService->getNotifications($user),
            static fn (Notification $notification): bool => str_starts_with($notification->getMessage(), 'Transfert recu |')
        );

        return array_slice(array_values($notifications), 0, $limit);
    }

    /**
     * @return Notification[]
     */
    private function getWalletNotificationsForUser(User $user, int $limit): array
    {
        $notifications = array_filter(
            $this->userService->getNotifications($user),
            static fn (Notification $notification): bool => str_contains($notification->getMessage(), 'wallet')
                || str_contains($notification->getMessage(), 'chequier')
                || str_contains($notification->getMessage(), 'Transfert ')
        );

        return array_slice(array_values($notifications), 0, $limit);
    }

    private function countUnreadTransferNotificationsForUser(User $user): int
    {
        return count(
            array_filter(
                $this->userService->getNotifications($user),
                static fn (Notification $notification): bool => str_starts_with($notification->getMessage(), 'Transfert recu |') && !$notification->isRead()
            )
        );
    }

    /**
     * @param Cheque[] $cheques
     * @return array{pending:int,accepted:int,refused:int,delivered:int}
     */
    private function buildChequeStats(array $cheques): array
    {
        $stats = [
            'pending' => 0,
            'accepted' => 0,
            'refused' => 0,
            'delivered' => 0,
        ];

        foreach ($cheques as $cheque) {
            $status = mb_strtolower($cheque->getStatut());

            if ($status === 'en_attente') {
                $stats['pending']++;
            } elseif ($status === 'accepte') {
                $stats['accepted']++;
            } elseif ($status === 'refuse') {
                $stats['refused']++;
            } elseif ($status === 'livre') {
                $stats['delivered']++;
            }
        }

        return $stats;
    }

    /**
     * @param Cheque[] $cheques
     * @return array<string, mixed>
     */
    private function buildWalletStats(?Wallet $wallet, array $cheques): array
    {
        if (!$wallet instanceof Wallet) {
            return [
                'statusLabel' => 'Aucun wallet',
                'statusTone' => 'neutral',
                'pendingCheques' => 0,
                'acceptedCheques' => 0,
                'refusedCheques' => 0,
                'deliveredCheques' => 0,
            ];
        }

        $chequeStats = $this->buildChequeStats($cheques);
        $status = mb_strtolower($wallet->getStatut());

        return [
            'statusLabel' => mb_strtoupper($wallet->getStatut()),
            'statusTone' => $status === 'bloque' ? 'danger' : ($status === 'suspendu' ? 'warning' : 'success'),
            'pendingCheques' => $chequeStats['pending'],
            'acceptedCheques' => $chequeStats['accepted'],
            'refusedCheques' => $chequeStats['refused'],
            'deliveredCheques' => $chequeStats['delivered'],
        ];
    }

    /**
     * @return array{labels:array<int,string>,values:array<int,float>}
     */
    private function buildTransactionChartData(Wallet $wallet): array
    {
        $transactions = $this->getLatestTransactions($wallet, 7);
        $transactions = array_reverse($transactions);

        $labels = [];
        $values = [];

        foreach ($transactions as $transaction) {
            $labels[] = $transaction->getDateTransaction()->format('d/m');
            $value = $transaction->getMontant();
            $values[] = mb_strtolower($transaction->getType()) === 'retrait' ? -$value : $value;
        }

        return [
            'labels' => $labels,
            'values' => $values,
        ];
    }
}
