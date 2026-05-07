<?php

namespace App\Controller\Admin;

use App\Entity\User\User;
use App\Entity\Wallet\Cheque;
use App\Form\Admin\ChequeRejectType;
use App\Service\ChequeSignatureService;
use App\Service\NotificationService;
use App\Service\WalletAuditService;
use App\Service\YousignService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
#[Route('/admin/cheque', name: 'admin_cheque_')]
class AdminChequeController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly NotificationService $notificationService,
        private readonly WalletAuditService $walletAuditService,
        private readonly ChequeSignatureService $chequeSignatureService,
        private readonly YousignService $yousignService,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $filters = $this->getChequeFilters($request);

        /** @var Cheque[] $cheques */
        $cheques = $this->createChequeListQueryBuilder($filters)
            ->orderBy('c.dateEmission', 'DESC')
            ->getQuery()
            ->getResult();

        return $this->render('admin/cheque/index.html.twig', [
            'cheques' => $cheques,
            'filters' => $filters,
        ]);
    }

    #[Route('/export/csv', name: 'export_csv', methods: ['GET'])]
    public function exportCsv(Request $request): StreamedResponse
    {
        $filters = $this->getChequeFilters($request);

        /** @var Cheque[] $cheques */
        $cheques = $this->createChequeListQueryBuilder($filters)
            ->orderBy('c.dateEmission', 'DESC')
            ->getQuery()
            ->getResult();

        $response = new StreamedResponse(function () use ($cheques) {
            $handle = fopen('php://output', 'w');
            if ($handle === false) {
                return;
            }

            fwrite($handle, "\xEF\xBB\xBF");

            fputcsv($handle, [
                'idCheque',
                'numeroCheque',
                'beneficiaire',
                'montant',
                'statut',
                'dateEmission',
                'wallet',
                'proprietaireWallet',
                'walletBloque',
                'motifRejet',
            ], ';');

            foreach ($cheques as $cheque) {
                $wallet = $cheque->getWallet();
                fputcsv($handle, [
                    $cheque->getIdCheque(),
                    $cheque->getNumeroCheque(),
                    $cheque->getBeneficiaire() ?? '',
                    $cheque->getMontant(),
                    $cheque->getStatut(),
                    $cheque->getDateEmission()->format('d/m/Y H:i'),
                    $wallet->getIdWallet(),
                    $wallet->getNomProprietaire(),
                    $wallet->getEstBloque() ? 'Oui' : 'Non',
                    $cheque->getMotifRejet() ?? '',
                ], ';');
            }

            fclose($handle);
        });

        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="fintrust_cheques_' . date('Ymd_His') . '.csv"');

        return $response;
    }

    #[Route('/export/pdf', name: 'export_pdf', methods: ['GET'])]
    public function exportPdf(Request $request): Response
    {
        $filters = $this->getChequeFilters($request);

        /** @var Cheque[] $cheques */
        $cheques = $this->createChequeListQueryBuilder($filters)
            ->orderBy('c.dateEmission', 'DESC')
            ->getQuery()
            ->getResult();

        return $this->render('admin/cheque/export_pdf.html.twig', [
            'cheques' => $cheques,
            'filters' => $filters,
        ]);
    }

    #[Route('/{id}', name: 'show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(int $id): Response
    {
        /** @var Cheque|null $cheque */
        $cheque = $this->entityManager->getRepository(Cheque::class)
            ->createQueryBuilder('c')
            ->leftJoin('c.wallet', 'w')->addSelect('w')
            ->andWhere('c.idCheque = :id')
            ->setParameter('id', $id)
            ->getQuery()
            ->getOneOrNullResult();

        if (!$cheque) {
            throw $this->createNotFoundException('Cheque introuvable.');
        }

        return $this->render('admin/cheque/show.html.twig', [
            'cheque' => $cheque,
            'signature' => $this->chequeSignatureService->verify($cheque),
            'yousignConfigured' => $this->yousignService->isConfigured(),
        ]);
    }

    #[Route('/{id}/signer', name: 'sign', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function sign(int $id, Request $request): Response
    {
        /** @var Cheque|null $cheque */
        $cheque = $this->entityManager->getRepository(Cheque::class)->find($id);

        if (!$cheque) {
            throw $this->createNotFoundException('Cheque introuvable.');
        }

        if (!$this->isCsrfTokenValid('sign_cheque_' . $cheque->getIdCheque(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Token CSRF invalide.');

            return $this->redirectToRoute('admin_cheque_show', ['id' => $cheque->getIdCheque()]);
        }

        /** @var User $admin */
        $admin = $this->getUser();
        $signature = $this->chequeSignatureService->sign($cheque, $admin);

        $this->addFlash('success', 'Validation signee generee. Empreinte: ' . substr($signature, 0, 16));

        return $this->redirectToRoute('admin_cheque_show', ['id' => $cheque->getIdCheque()]);
    }

    #[Route('/{id}/approve', name: 'approve', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function approve(int $id, Request $request): Response
    {
        /** @var Cheque|null $cheque */
        $cheque = $this->entityManager->getRepository(Cheque::class)->find($id);

        if (!$cheque) {
            throw $this->createNotFoundException('Cheque introuvable.');
        }

        if (!$this->isCsrfTokenValid('approve_cheque_' . $cheque->getIdCheque(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Token CSRF invalide.');
            return $this->redirectToRoute('admin_cheque_index');
        }

        if ($cheque->getWallet()->getEstBloque()) {
            $this->addFlash('error', 'Impossible d approuver ce cheque car le wallet associe est bloque.');
            return $this->redirectToRoute('admin_cheque_show', ['id' => $cheque->getIdCheque()]);
        }

        $signature = $this->chequeSignatureService->verify($cheque);
        if (!$signature['signed'] || !$signature['valid']) {
            $this->addFlash('warning', 'Une signature admin valide est requise avant approbation du cheque.');

            return $this->redirectToRoute('admin_cheque_show', ['id' => $cheque->getIdCheque()]);
        }

        $cheque->setStatut('accepte');
        $cheque->setMotifRejet(null);
        $this->entityManager->flush();

        if ($user = $this->resolveUserForCheque($cheque)) {
            $this->notificationService->notifyChequeApproved($user, $cheque->getNumeroCheque());
        }

        $this->walletAuditService->logChequeAction(
            'wallet.cheque.approved',
            $cheque->getIdCheque(),
            $cheque->getWallet()->getIdWallet(),
            $this->resolveUserForCheque($cheque)?->getId(),
            $cheque->getStatut()
        );

        $this->addFlash('success', 'Le cheque a ete approuve avec succes.');

        return $this->redirectToRoute('admin_cheque_show', ['id' => $cheque->getIdCheque()]);
    }

    #[Route('/{id}/reject', name: 'reject', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function reject(int $id, Request $request): Response
    {
        /** @var Cheque|null $cheque */
        $cheque = $this->entityManager->getRepository(Cheque::class)->find($id);

        if (!$cheque) {
            throw $this->createNotFoundException('Cheque introuvable.');
        }

        $form = $this->createForm(ChequeRejectType::class, $cheque);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $cheque->setStatut('refuse');
            $this->entityManager->flush();

            if ($user = $this->resolveUserForCheque($cheque)) {
                $this->notificationService->notifyChequeRejected($user, $cheque->getNumeroCheque(), $cheque->getMotifRejet());
            }

            $this->walletAuditService->logChequeAction(
                'wallet.cheque.rejected',
                $cheque->getIdCheque(),
                $cheque->getWallet()->getIdWallet(),
                $this->resolveUserForCheque($cheque)?->getId(),
                $cheque->getStatut(),
                $cheque->getMotifRejet()
            );

            $this->addFlash('warning', 'Le cheque a ete refuse et le motif a ete enregistre.');

            return $this->redirectToRoute('admin_cheque_show', ['id' => $cheque->getIdCheque()]);
        }

        return $this->render('admin/cheque/reject.html.twig', [
            'cheque' => $cheque,
            'form' => $form,
        ]);
    }

    #[Route('/{id}/initiate-yousign', name: 'initiate_yousign', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function initiateYousign(int $id, Request $request): Response
    {
        /** @var Cheque|null $cheque */
        $cheque = $this->entityManager->getRepository(Cheque::class)->find($id);

        if (!$cheque) {
            throw $this->createNotFoundException('Cheque introuvable.');
        }

        if (!$this->isCsrfTokenValid('yousign_cheque_' . $cheque->getIdCheque(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Token CSRF invalide.');
            return $this->redirectToRoute('admin_cheque_show', ['id' => $cheque->getIdCheque()]);
        }

        if ($cheque->getYousignStatus() === 'signed') {
            $this->addFlash('warning', 'Ce cheque a deja ete signe electroniquement via Yousign.');
            return $this->redirectToRoute('admin_cheque_show', ['id' => $cheque->getIdCheque()]);
        }

        $user = $this->resolveUserForCheque($cheque);
        if (!$user) {
            $this->addFlash('error', 'Impossible de trouver le client associe a ce cheque.');
            return $this->redirectToRoute('admin_cheque_show', ['id' => $cheque->getIdCheque()]);
        }
        if (!filter_var($user->getEmail(), FILTER_VALIDATE_EMAIL)) {
            $this->addFlash('error', 'Le client associe n a pas d adresse email valide pour Yousign.');

            return $this->redirectToRoute('admin_cheque_show', ['id' => $cheque->getIdCheque()]);
        }

        try {
            $this->logger->info('Yousign: demande de signature declenchee depuis l admin.', [
                'cheque_id' => $cheque->getIdCheque(),
                'wallet_id' => $cheque->getWallet()->getIdWallet(),
                'user_id' => $user->getId(),
                'signer_email' => $user->getEmail(),
            ]);

            $result = $this->yousignService->initiateSignature(
                $cheque,
                $user->getEmail(),
                $user->getPrenom(),
                $user->getNom()
            );

            $cheque->setYousignProcedureId($result['procedure_id']);
            $cheque->setYousignStatus('pending');
            $cheque->setYousignSigningLink($result['signing_link']);
            $cheque->setYousignSignedAt(null);
            $this->entityManager->flush();

            $this->logger->info('Yousign: cheque mis en attente de signature.', [
                'cheque_id' => $cheque->getIdCheque(),
                'procedure_id' => $result['procedure_id'],
                'status' => $cheque->getYousignStatus(),
                'signing_link_saved' => $result['signing_link'] !== '',
            ]);

            $this->walletAuditService->logChequeAction(
                'wallet.cheque.yousign_initiated',
                $cheque->getIdCheque(),
                $cheque->getWallet()->getIdWallet(),
                $user->getId(),
                'yousign_pending'
            );

            $this->addFlash(
                'success',
                sprintf(
                    'Demande Yousign envoyee a %s. Statut: en attente de signature. ID Yousign: %s.',
                    $user->getEmail(),
                    $result['procedure_id']
                )
            );
        } catch (\Throwable $e) {
            $this->logger->error('Erreur lors de l initialisation Yousign cheque.', [
                'cheque_id' => $cheque->getIdCheque(),
                'wallet_id' => $cheque->getWallet()->getIdWallet(),
                'user_id' => $user->getId(),
                'exception' => $e::class,
                'error' => $e->getMessage(),
            ]);
            $this->addFlash('error', 'Erreur Yousign : ' . $e->getMessage());
        }

        return $this->redirectToRoute('admin_cheque_show', ['id' => $cheque->getIdCheque()]);
    }

    #[Route('/{id}/deliver', name: 'deliver', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function deliver(int $id, Request $request): Response
    {
        /** @var Cheque|null $cheque */
        $cheque = $this->entityManager->getRepository(Cheque::class)->find($id);

        if (!$cheque) {
            throw $this->createNotFoundException('Cheque introuvable.');
        }

        if (!$this->isCsrfTokenValid('deliver_cheque_' . $cheque->getIdCheque(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Token CSRF invalide.');
            return $this->redirectToRoute('admin_cheque_show', ['id' => $cheque->getIdCheque()]);
        }

        $signature = $this->chequeSignatureService->verify($cheque);
        if (!$signature['signed']) {
            $this->addFlash('warning', 'Le cheque doit posseder une validation signee avant livraison.');

            return $this->redirectToRoute('admin_cheque_show', ['id' => $cheque->getIdCheque()]);
        }

        $cheque->setStatut('livre');
        $cheque->setDatePresentation(new \DateTime());
        $this->entityManager->flush();

        if ($user = $this->resolveUserForCheque($cheque)) {
            $this->notificationService->notifyChequeDelivered($user, $cheque->getNumeroCheque());
        }

        $this->walletAuditService->logChequeAction(
            'wallet.cheque.delivered',
            $cheque->getIdCheque(),
            $cheque->getWallet()->getIdWallet(),
            $this->resolveUserForCheque($cheque)?->getId(),
            $cheque->getStatut()
        );

        $this->addFlash('success', 'Le chequier a ete marque comme livre.');

        return $this->redirectToRoute('admin_cheque_show', ['id' => $cheque->getIdCheque()]);
    }

    /**
     * @return array{numero:string,beneficiaire:string,statut:string,wallet:string}
     */
    private function getChequeFilters(Request $request): array
    {
        return [
            'numero' => trim((string) $request->query->get('numero', '')),
            'beneficiaire' => trim((string) $request->query->get('beneficiaire', '')),
            'statut' => trim((string) $request->query->get('statut', '')),
            'wallet' => trim((string) $request->query->get('wallet', '')),
        ];
    }

    /**
     * @param array{numero:string,beneficiaire:string,statut:string,wallet:string} $filters
     */
    private function createChequeListQueryBuilder(array $filters): QueryBuilder
    {
        $qb = $this->entityManager->getRepository(Cheque::class)->createQueryBuilder('c')
            ->leftJoin('c.wallet', 'w')
            ->addSelect('w');

        if ($filters['numero'] !== '') {
            $qb
                ->andWhere('LOWER(c.numeroCheque) LIKE :numero')
                ->setParameter('numero', '%' . mb_strtolower($filters['numero']) . '%');
        }

        if ($filters['beneficiaire'] !== '') {
            $qb
                ->andWhere('LOWER(c.beneficiaire) LIKE :beneficiaire')
                ->setParameter('beneficiaire', '%' . mb_strtolower($filters['beneficiaire']) . '%');
        }

        if ($filters['statut'] !== '') {
            $qb
                ->andWhere('LOWER(c.statut) = :statut')
                ->setParameter('statut', mb_strtolower($filters['statut']));
        }

        if ($filters['wallet'] !== '') {
            $qb
                ->join('c.wallet', 'wallet_filter')
                ->andWhere('wallet_filter.idWallet = :walletId')
                ->setParameter('walletId', (int) $filters['wallet']);
        }

        return $qb;
    }

    private function resolveUserForCheque(Cheque $cheque): ?User
    {
        $wallet = $cheque->getWallet();

        if ($wallet->getUser() instanceof User) {
            return $wallet->getUser();
        }

        if ($wallet->getIdUser() !== null) {
            /** @var User|null $user */
            $user = $this->entityManager->getRepository(User::class)->find($wallet->getIdUser());

            return $user;
        }

        return null;
    }
}
