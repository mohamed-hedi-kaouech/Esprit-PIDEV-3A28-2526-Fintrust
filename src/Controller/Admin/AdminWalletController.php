<?php

namespace App\Controller\Admin;

use App\Entity\User\User;
use App\Entity\Wallet\Cheque;
use App\Entity\Wallet\Transaction;
use App\Entity\Wallet\Wallet;
use App\Service\NotificationService;
use App\Service\WalletAuditService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
#[Route('/admin/wallet', name: 'admin_wallet_')]
class AdminWalletController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly NotificationService $notificationService,
        private readonly WalletAuditService $walletAuditService,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $filters = $this->getWalletFilters($request);

        /** @var Wallet[] $wallets */
        $wallets = $this->createWalletListQueryBuilder($filters)
            ->orderBy('w.dateCreation', 'DESC')
            ->getQuery()
            ->getResult();

        $walletRepository = $this->entityManager->getRepository(Wallet::class);
        $overview = [
            'total' => (int) $walletRepository->createQueryBuilder('w')->select('COUNT(w.idWallet)')->getQuery()->getSingleScalarResult(),
            'active' => (int) $walletRepository->createQueryBuilder('w')->select('COUNT(w.idWallet)')->andWhere('w.estActif = :active')->setParameter('active', true)->getQuery()->getSingleScalarResult(),
            'blocked' => (int) $walletRepository->createQueryBuilder('w')->select('COUNT(w.idWallet)')->andWhere('w.estBloque = :blocked')->setParameter('blocked', true)->getQuery()->getSingleScalarResult(),
            'linked' => (int) $walletRepository->createQueryBuilder('w')->select('COUNT(w.idWallet)')->andWhere('w.idUser IS NOT NULL')->getQuery()->getSingleScalarResult(),
            'suspended' => (int) $walletRepository->createQueryBuilder('w')->select('COUNT(w.idWallet)')->andWhere('LOWER(w.statut) = :status')->setParameter('status', 'suspendu')->getQuery()->getSingleScalarResult(),
        ];

        $devises = array_map(
            static fn (array $row): string => (string) $row['devise'],
            $walletRepository->createQueryBuilder('w')
                ->select('DISTINCT w.devise AS devise')
                ->orderBy('w.devise', 'ASC')
                ->getQuery()
                ->getArrayResult()
        );

        return $this->render('admin/wallet/index.html.twig', [
            'wallets' => $wallets,
            'overview' => $overview,
            'devises' => $devises,
            'filters' => $filters,
        ]);
    }

    #[Route('/statistiques', name: 'stats', methods: ['GET'])]
    public function stats(): Response
    {
        $walletRepository = $this->entityManager->getRepository(Wallet::class);
        $transactionRepository = $this->entityManager->getRepository(Transaction::class);
        $chequeRepository = $this->entityManager->getRepository(Cheque::class);

        $stats = [
            'walletsTotal' => (int) $walletRepository->createQueryBuilder('w')->select('COUNT(w.idWallet)')->getQuery()->getSingleScalarResult(),
            'walletsActive' => (int) $walletRepository->createQueryBuilder('w')->select('COUNT(w.idWallet)')->andWhere('w.estActif = :active')->setParameter('active', true)->getQuery()->getSingleScalarResult(),
            'walletsBlocked' => (int) $walletRepository->createQueryBuilder('w')->select('COUNT(w.idWallet)')->andWhere('w.estBloque = :blocked')->setParameter('blocked', true)->getQuery()->getSingleScalarResult(),
            'walletsSuspended' => (int) $walletRepository->createQueryBuilder('w')->select('COUNT(w.idWallet)')->andWhere('LOWER(w.statut) = :status')->setParameter('status', 'suspendu')->getQuery()->getSingleScalarResult(),
            'transactionsTotal' => (int) $transactionRepository->createQueryBuilder('t')->select('COUNT(t.idTransaction)')->getQuery()->getSingleScalarResult(),
            'transfersTotal' => (int) $transactionRepository->createQueryBuilder('t')->select('COUNT(t.idTransaction)')->andWhere('LOWER(t.type) = :type')->setParameter('type', 'transfert')->getQuery()->getSingleScalarResult(),
            'chequesTotal' => (int) $chequeRepository->createQueryBuilder('c')->select('COUNT(c.idCheque)')->getQuery()->getSingleScalarResult(),
            'chequesPending' => (int) $chequeRepository->createQueryBuilder('c')->select('COUNT(c.idCheque)')->andWhere('LOWER(c.statut) = :statut')->setParameter('statut', 'en_attente')->getQuery()->getSingleScalarResult(),
            'chequesAccepted' => (int) $chequeRepository->createQueryBuilder('c')->select('COUNT(c.idCheque)')->andWhere('LOWER(c.statut) = :statut')->setParameter('statut', 'accepte')->getQuery()->getSingleScalarResult(),
            'chequesRefused' => (int) $chequeRepository->createQueryBuilder('c')->select('COUNT(c.idCheque)')->andWhere('LOWER(c.statut) = :statut')->setParameter('statut', 'refuse')->getQuery()->getSingleScalarResult(),
            'chequesDelivered' => (int) $chequeRepository->createQueryBuilder('c')->select('COUNT(c.idCheque)')->andWhere('LOWER(c.statut) = :statut')->setParameter('statut', 'livre')->getQuery()->getSingleScalarResult(),
        ];

        /** @var Wallet[] $latestWallets */
        $latestWallets = $walletRepository->createQueryBuilder('w')->orderBy('w.dateCreation', 'DESC')->setMaxResults(8)->getQuery()->getResult();
        /** @var Cheque[] $latestCheques */
        $latestCheques = $chequeRepository->createQueryBuilder('c')->leftJoin('c.wallet', 'w')->addSelect('w')->orderBy('c.dateEmission', 'DESC')->setMaxResults(8)->getQuery()->getResult();
        /** @var Wallet[] $blockedWallets */
        $blockedWallets = $walletRepository->createQueryBuilder('w')->andWhere('w.estBloque = :blocked')->setParameter('blocked', true)->orderBy('w.dateCreation', 'DESC')->setMaxResults(8)->getQuery()->getResult();

        $topActiveWalletRows = $transactionRepository->createQueryBuilder('t')
            ->select('IDENTITY(t.wallet) AS walletId, COUNT(t.idTransaction) AS txCount, SUM(t.montant) AS totalAmount')
            ->groupBy('t.wallet')
            ->orderBy('txCount', 'DESC')
            ->addOrderBy('totalAmount', 'DESC')
            ->setMaxResults(5)
            ->getQuery()
            ->getArrayResult();

        $topActiveWallets = [];
        foreach ($topActiveWalletRows as $row) {
            $wallet = $walletRepository->find((int) $row['walletId']);
            if ($wallet instanceof Wallet) {
                $topActiveWallets[] = [
                    'wallet' => $wallet,
                    'txCount' => (int) $row['txCount'],
                    'totalAmount' => (float) $row['totalAmount'],
                ];
            }
        }

        /** @var Wallet[] $allWalletsForChart */
        $allWalletsForChart = $walletRepository->createQueryBuilder('w')->orderBy('w.dateCreation', 'ASC')->getQuery()->getResult();
        $monthlyMap = [];
        $cursor = new \DateTimeImmutable('first day of this month -5 months');
        for ($i = 0; $i < 6; $i++) {
            $monthlyMap[$cursor->format('Y-m')] = ['label' => $cursor->format('M Y'), 'count' => 0];
            $cursor = $cursor->modify('+1 month');
        }
        foreach ($allWalletsForChart as $wallet) {
            $key = $wallet->getDateCreation()->format('Y-m');
            if (isset($monthlyMap[$key])) {
                $monthlyMap[$key]['count']++;
            }
        }

        return $this->render('admin/wallet/stats.html.twig', [
            'stats' => $stats,
            'latestWallets' => $latestWallets,
            'latestCheques' => $latestCheques,
            'blockedWallets' => $blockedWallets,
            'topActiveWallets' => $topActiveWallets,
            'walletsByMonth' => array_values($monthlyMap),
            'auditEntries' => $this->walletAuditService->getRecentEntries(12),
        ]);
    }

    #[Route('/audit', name: 'audit', methods: ['GET'])]
    public function audit(): Response
    {
        return $this->render('admin/wallet/audit.html.twig', [
            'entries' => $this->walletAuditService->getRecentEntries(150),
        ]);
    }

    #[Route('/export/csv', name: 'export_csv', methods: ['GET'])]
    public function exportCsv(Request $request): StreamedResponse
    {
        $filters = $this->getWalletFilters($request);

        /** @var Wallet[] $wallets */
        $wallets = $this->createWalletListQueryBuilder($filters)->orderBy('w.dateCreation', 'DESC')->getQuery()->getResult();

        $response = new StreamedResponse(function () use ($wallets) {
            $handle = fopen('php://output', 'w');
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, ['idWallet', 'nomProprietaire', 'email', 'telephone', 'solde', 'devise', 'statut', 'estActif', 'estBloque', 'dateCreation'], ';');
            foreach ($wallets as $wallet) {
                fputcsv($handle, [
                    $wallet->getIdWallet(),
                    $wallet->getNomProprietaire(),
                    $wallet->getEmail() ?? '',
                    $wallet->getTelephone() ?? '',
                    $wallet->getSolde(),
                    $wallet->getDevise(),
                    $wallet->getStatut(),
                    $wallet->getEstActif() ? 'Oui' : 'Non',
                    $wallet->getEstBloque() ? 'Oui' : 'Non',
                    $wallet->getDateCreation()->format('d/m/Y H:i'),
                ], ';');
            }
            fclose($handle);
        });

        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="fintrust_wallets_' . date('Ymd_His') . '.csv"');

        return $response;
    }

    #[Route('/{id}', name: 'show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(int $id): Response
    {
        /** @var Wallet|null $wallet */
        $wallet = $this->entityManager->getRepository(Wallet::class)->find($id);

        if (!$wallet) {
            throw $this->createNotFoundException('Wallet introuvable.');
        }

        /** @var Transaction[] $latestTransactions */
        $latestTransactions = $this->entityManager->getRepository(Transaction::class)
            ->createQueryBuilder('t')
            ->andWhere('t.idWallet = :walletId')
            ->setParameter('walletId', $wallet->getIdWallet())
            ->orderBy('t.dateTransaction', 'DESC')
            ->setMaxResults(10)
            ->getQuery()
            ->getResult();

        $transactionStats = [
            'depotCount' => 0,
            'retraitCount' => 0,
            'transfertCount' => 0,
            'depotAmount' => 0.0,
            'retraitAmount' => 0.0,
            'transfertAmount' => 0.0,
        ];

        foreach ($wallet->getTransactions() as $transaction) {
            $type = mb_strtolower($transaction->getType());
            if ($type === 'depot') {
                $transactionStats['depotCount']++;
                $transactionStats['depotAmount'] += $transaction->getMontant();
            } elseif ($type === 'retrait') {
                $transactionStats['retraitCount']++;
                $transactionStats['retraitAmount'] += $transaction->getMontant();
            } elseif ($type === 'transfert') {
                $transactionStats['transfertCount']++;
                $transactionStats['transfertAmount'] += $transaction->getMontant();
            }
        }

        /** @var Cheque[] $latestCheques */
        $latestCheques = $this->entityManager->getRepository(Cheque::class)
            ->createQueryBuilder('c')
            ->andWhere('c.idWallet = :walletId')
            ->setParameter('walletId', $wallet->getIdWallet())
            ->orderBy('c.dateEmission', 'DESC')
            ->setMaxResults(10)
            ->getQuery()
            ->getResult();

        return $this->render('admin/wallet/show.html.twig', [
            'wallet' => $wallet,
            'latestTransactions' => $latestTransactions,
            'transactionStats' => $transactionStats,
            'latestCheques' => $latestCheques,
            'auditEntries' => $this->walletAuditService->getRecentEntries(20, $wallet->getIdUser()),
        ]);
    }

    #[Route('/{id}/block', name: 'block', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function block(int $id, Request $request): Response
    {
        return $this->applyWalletStatus($id, $request, 'bloque', false, true, 'wallet_block_');
    }

    #[Route('/{id}/unblock', name: 'unblock', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function unblock(int $id, Request $request): Response
    {
        return $this->applyWalletStatus($id, $request, 'actif', true, false, 'wallet_unblock_');
    }

    #[Route('/{id}/suspend', name: 'suspend', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function suspend(int $id, Request $request): Response
    {
        return $this->applyWalletStatus($id, $request, 'suspendu', false, false, 'wallet_suspend_');
    }

    #[Route('/{id}/activate', name: 'activate', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function activate(int $id, Request $request): Response
    {
        return $this->applyWalletStatus($id, $request, 'actif', true, false, 'wallet_activate_');
    }

    #[Route('/nouveau', name: 'new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $wallet = new Wallet();
        $wallet->setNomProprietaire('')->setSolde('0.00')->setDevise('TND')->setStatut('actif')->setEstActif(true)->setEstBloque(false)->setPlafondDecouvert('0.00');

        if ($request->isMethod('POST')) {
            $this->hydrateWalletFromRequest($wallet, $request);

            if ($error = $this->validateWallet($wallet)) {
                $this->addFlash('error', $error);
            } else {
                $wallet->setDateCreation(new \DateTime());
                $this->entityManager->persist($wallet);
                $this->entityManager->flush();
                $this->walletAuditService->logWalletStatusChange($wallet->getIdWallet(), $wallet->getIdUser(), $wallet->getStatut(), (bool) $wallet->getEstActif(), (bool) $wallet->getEstBloque());
                $this->addFlash('success', 'Le wallet a ete cree avec succes.');

                return $this->redirectToRoute('admin_wallet_index');
            }
        }

        return $this->render('admin/wallet/form.html.twig', [
            'wallet' => $wallet,
            'clients' => $this->getWalletClients(),
            'selectedUserId' => $wallet->getIdUser(),
            'isEdit' => false,
        ]);
    }

    #[Route('/{id}/modifier', name: 'edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function edit(int $id, Request $request): Response
    {
        /** @var Wallet|null $wallet */
        $wallet = $this->entityManager->getRepository(Wallet::class)->find($id);
        if (!$wallet) {
            throw $this->createNotFoundException('Wallet introuvable.');
        }

        $previousStatus = $wallet->getStatut();

        if ($request->isMethod('POST')) {
            $this->hydrateWalletFromRequest($wallet, $request);

            if ($error = $this->validateWallet($wallet)) {
                $this->addFlash('error', $error);
            } else {
                $this->entityManager->flush();
                if ($previousStatus !== $wallet->getStatut()) {
                    $this->notifyWalletUser($wallet);
                    $this->walletAuditService->logWalletStatusChange($wallet->getIdWallet(), $wallet->getIdUser(), $wallet->getStatut(), (bool) $wallet->getEstActif(), (bool) $wallet->getEstBloque());
                }
                $this->addFlash('success', 'Le wallet a ete mis a jour avec succes.');

                return $this->redirectToRoute('admin_wallet_show', ['id' => $wallet->getIdWallet()]);
            }
        }

        return $this->render('admin/wallet/form.html.twig', [
            'wallet' => $wallet,
            'clients' => $this->getWalletClients(),
            'selectedUserId' => $wallet->getIdUser(),
            'isEdit' => true,
        ]);
    }

    #[Route('/{id}/supprimer', name: 'delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function delete(int $id, Request $request): Response
    {
        /** @var Wallet|null $wallet */
        $wallet = $this->entityManager->getRepository(Wallet::class)->find($id);
        if (!$wallet) {
            throw $this->createNotFoundException('Wallet introuvable.');
        }

        if (!$this->isCsrfTokenValid('wallet_delete_' . $wallet->getIdWallet(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Token CSRF invalide.');
            return $this->redirectToRoute('admin_wallet_index');
        }

        $walletId = $wallet->getIdWallet();
        $userId = $wallet->getIdUser();

        $this->entityManager->getRepository(Transaction::class)
            ->createQueryBuilder('t')
            ->delete()
            ->andWhere('t.idWallet = :walletId')
            ->setParameter('walletId', $walletId)
            ->getQuery()
            ->execute();

        $this->entityManager->getRepository(Cheque::class)
            ->createQueryBuilder('c')
            ->delete()
            ->andWhere('c.idWallet = :walletId')
            ->setParameter('walletId', $walletId)
            ->getQuery()
            ->execute();

        $this->entityManager->remove($wallet);
        $this->entityManager->flush();

        $this->walletAuditService->log('wallet.deleted', [
            'wallet_id' => $walletId,
            'user_id' => $userId,
        ]);

        $this->addFlash('success', 'Le wallet a ete supprime avec succes.');

        return $this->redirectToRoute('admin_wallet_index');
    }

    /**
     * @return User[]
     */
    private function getWalletClients(): array
    {
        /** @var User[] $clients */
        $clients = $this->entityManager->getRepository(User::class)->createQueryBuilder('u')
            ->andWhere('u.role = :role')
            ->setParameter('role', User::ROLE_CLIENT)
            ->orderBy('u.createdAt', 'DESC')
            ->getQuery()
            ->getResult();

        return $clients;
    }

    /**
     * @return array{search:string,statut:string,blocked:string,devise:string}
     */
    private function getWalletFilters(Request $request): array
    {
        return [
            'search' => trim((string) $request->query->get('search', '')),
            'statut' => trim((string) $request->query->get('statut', '')),
            'blocked' => (string) $request->query->get('blocked', ''),
            'devise' => trim((string) $request->query->get('devise', '')),
        ];
    }

    /**
     * @param array{search:string,statut:string,blocked:string,devise:string} $filters
     */
    private function createWalletListQueryBuilder(array $filters): QueryBuilder
    {
        $qb = $this->entityManager->getRepository(Wallet::class)->createQueryBuilder('w');
        if ($filters['search'] !== '') {
            $qb->andWhere('LOWER(w.nomProprietaire) LIKE :search OR LOWER(w.email) LIKE :search')->setParameter('search', '%' . mb_strtolower($filters['search']) . '%');
        }
        if ($filters['statut'] !== '') {
            $qb->andWhere('w.statut = :statut')->setParameter('statut', $filters['statut']);
        }
        if ($filters['blocked'] !== '') {
            $qb->andWhere('w.estBloque = :blocked')->setParameter('blocked', $filters['blocked'] === '1');
        }
        if ($filters['devise'] !== '') {
            $qb->andWhere('w.devise = :devise')->setParameter('devise', $filters['devise']);
        }

        return $qb;
    }

    private function hydrateWalletFromRequest(Wallet $wallet, Request $request): void
    {
        $selectedUserId = $request->request->get('id_user');
        $selectedUserId = $selectedUserId !== null && $selectedUserId !== '' ? (int) $selectedUserId : null;
        $user = null;
        if ($selectedUserId !== null) {
            $user = $this->entityManager->getRepository(User::class)->find($selectedUserId);
        }

        $nomProprietaire = trim((string) $request->request->get('nomProprietaire', ''));
        $email = trim((string) $request->request->get('email', ''));
        $telephone = trim((string) $request->request->get('telephone', ''));

        if ($user instanceof User) {
            $wallet->setIdUser($user->getId());
            if ($nomProprietaire === '') {
                $nomProprietaire = $user->getFullName();
            }
            if ($email === '') {
                $email = $user->getEmail();
            }
            if ($telephone === '') {
                $telephone = (string) ($user->getNumTel() ?? '');
            }
        } else {
            $wallet->setIdUser(null);
        }

        $wallet->setNomProprietaire($nomProprietaire);
        $wallet->setEmail($email !== '' ? $email : null);
        $wallet->setTelephone($telephone !== '' ? $telephone : null);
        $wallet->setSolde($this->normalizeDecimal((string) $request->request->get('solde', '0')));
        $wallet->setPlafondDecouvert($this->normalizeNullableDecimal($request->request->get('plafondDecouvert')));
        $wallet->setDevise(trim((string) $request->request->get('devise', 'TND')) ?: 'TND');
        $wallet->setCodeAcces($this->normalizeNullableString($request->request->get('codeAcces')));
        $wallet->setStatut(trim((string) $request->request->get('statut', 'actif')) ?: 'actif');
        $wallet->setEstActif($request->request->getBoolean('estActif'));
        $wallet->setEstBloque($request->request->getBoolean('estBloque'));

        if ($wallet->getEstBloque()) {
            $wallet->setEstActif(false);
            $wallet->setStatut('bloque');
        }

        $wallet->setTentativesEchouees((int) $request->request->get('tentativesEchouees', 0));
    }

    private function validateWallet(Wallet $wallet): ?string
    {
        if (trim($wallet->getNomProprietaire()) === '') {
            return 'Le nom du proprietaire est obligatoire.';
        }
        if ($wallet->getEmail() !== null && $wallet->getEmail() !== '' && !filter_var($wallet->getEmail(), FILTER_VALIDATE_EMAIL)) {
            return 'L adresse email du wallet est invalide.';
        }
        if (trim($wallet->getDevise()) === '') {
            return 'La devise est obligatoire.';
        }

        return null;
    }

    private function normalizeDecimal(string $value): string
    {
        $normalized = str_replace(',', '.', trim($value));
        if ($normalized === '' || !is_numeric($normalized)) {
            return '0.00';
        }

        return number_format((float) $normalized, 2, '.', '');
    }

    private function normalizeNullableDecimal(mixed $value): ?string
    {
        $normalized = str_replace(',', '.', trim((string) $value));
        if ($normalized === '') {
            return null;
        }
        if (!is_numeric($normalized)) {
            return '0.00';
        }

        return number_format((float) $normalized, 2, '.', '');
    }

    private function normalizeNullableString(mixed $value): ?string
    {
        $normalized = trim((string) $value);

        return $normalized !== '' ? $normalized : null;
    }

    private function applyWalletStatus(int $id, Request $request, string $status, bool $active, bool $blocked, string $tokenPrefix): Response
    {
        /** @var Wallet|null $wallet */
        $wallet = $this->entityManager->getRepository(Wallet::class)->find($id);
        if (!$wallet) {
            throw $this->createNotFoundException('Wallet introuvable.');
        }

        if (!$this->isCsrfTokenValid($tokenPrefix . $wallet->getIdWallet(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Token CSRF invalide.');
            return $this->redirectToRoute('admin_wallet_index');
        }

        $wallet->setEstBloque($blocked);
        $wallet->setEstActif($active);
        $wallet->setStatut($status);
        $this->entityManager->flush();

        $this->notifyWalletUser($wallet);
        $this->walletAuditService->logWalletStatusChange($wallet->getIdWallet(), $wallet->getIdUser(), $status, $active, $blocked);

        $this->addFlash('success', sprintf('Le wallet #%d de %s est maintenant %s.', $wallet->getIdWallet(), $wallet->getNomProprietaire(), $status));

        return $this->redirectToRoute('admin_wallet_index');
    }

    private function notifyWalletUser(Wallet $wallet): void
    {
        if ($wallet->getIdUser() === null) {
            return;
        }

        /** @var User|null $user */
        $user = $this->entityManager->getRepository(User::class)->find($wallet->getIdUser());
        if ($user instanceof User) {
            $this->notificationService->notifyWalletStatusChanged($user, $wallet->getStatut());
        }
    }
}
