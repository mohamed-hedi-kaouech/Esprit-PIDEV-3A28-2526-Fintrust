<?php

namespace App\Controller\Admin;

use App\Entity\User\User;
use App\Entity\Wallet\Cheque;
use App\Form\Admin\ChequeRejectType;
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
#[Route('/admin/cheque', name: 'admin_cheque_')]
class AdminChequeController extends AbstractController
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
        ]);
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

        $cheque->setStatut('livre');
        $cheque->setDatePresentation(new \DateTimeImmutable());
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
                ->andWhere('c.idWallet = :walletId')
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
