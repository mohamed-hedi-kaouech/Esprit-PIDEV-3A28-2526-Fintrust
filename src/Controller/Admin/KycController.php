<?php

namespace App\Controller\Admin;

use App\Repository\KycRepository;
use App\Service\KycService;
use App\Service\NotificationService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Contrôleur Admin — Gestion des dossiers KYC (BackOffice)
 *
 * Liste, aperçu des fichiers, approbation et refus des dossiers KYC.
 */
#[IsGranted('ROLE_ADMIN')]
#[Route('/admin/kyc', name: 'admin_kyc_')]
class KycController extends AbstractController
{
    public function __construct(
        private readonly KycRepository       $kycRepository,
        private readonly KycService          $kycService,
        private readonly NotificationService $notificationService,
    ) {}

    // =========================================================================
    // LISTE DES KYC EN ATTENTE
    // =========================================================================

    /**
     * Affiche tous les dossiers KYC en attente de validation.
     */
    #[Route('', name: 'list')]
    public function list(): Response
    {
        $pending = $this->kycRepository->findPending();
        $overview = $this->kycRepository->getAdminOverview();
        $recentActivity = $this->kycRepository->findRecentActivity();

        return $this->render('admin/kyc/list.html.twig', [
            'pendingKyc' => $pending,
            'overview' => $overview,
            'recentActivity' => $recentActivity,
        ]);
    }

    // =========================================================================
    // APERÇU D'UN DOSSIER KYC
    // =========================================================================

    /**
     * Affiche le détail d'un dossier KYC avec ses fichiers justificatifs.
     * Les fichiers binaires sont convertis en base64 pour affichage inline.
     */
    #[Route('/{id}', name: 'view', methods: ['GET'])]
    public function view(int $id): Response
    {
        $kyc = $this->kycRepository->findWithFiles($id);

        if (!$kyc) {
            throw $this->createNotFoundException('Dossier KYC introuvable.');
        }

        // Conversion des fichiers en base64 pour affichage inline (images/PDF)
        $filesData = [];
        foreach ($kyc->getFiles() as $file) {
            $filesData[] = [
                'file'   => $file,
                'base64' => $this->kycService->getFileBase64($file),
            ];
        }

        return $this->render('admin/kyc/view.html.twig', [
            'kyc'       => $kyc,
            'filesData' => $filesData,
        ]);
    }

    // =========================================================================
    // APPROBATION
    // =========================================================================

    /**
     * Approuve un dossier KYC et active le compte utilisateur.
     * Envoie une notification interne au client.
     */
    #[Route('/{id}/approuver', name: 'approve', methods: ['POST'])]
    public function approve(int $id, Request $request): Response
    {
        $kyc = $this->kycRepository->find($id);

        if (!$kyc) {
            throw $this->createNotFoundException();
        }

        if (!$this->isCsrfTokenValid('kyc_approve_' . $id, $request->request->get('_token'))) {
            $this->addFlash('error', 'Token CSRF invalide.');
            return $this->redirectToRoute('admin_kyc_list');
        }

        $this->kycService->approveKyc($kyc, $request->request->get('commentaire'));
        $this->notificationService->notifyKycApproved($kyc->getUser());

        $this->addFlash('success', "✔ KYC de {$kyc->getUser()->getFullName()} approuvé. Compte activé.");

        return $this->redirectToRoute('admin_kyc_list');
    }

    // =========================================================================
    // REFUS
    // =========================================================================

    /**
     * Refuse un dossier KYC avec un motif obligatoire.
     * Envoie une notification interne au client avec le motif.
     */
    #[Route('/{id}/refuser', name: 'refuse', methods: ['POST'])]
    public function refuse(int $id, Request $request): Response
    {
        $kyc = $this->kycRepository->find($id);

        if (!$kyc) {
            throw $this->createNotFoundException();
        }

        if (!$this->isCsrfTokenValid('kyc_refuse_' . $id, $request->request->get('_token'))) {
            $this->addFlash('error', 'Token CSRF invalide.');
            return $this->redirectToRoute('admin_kyc_list');
        }

        $commentaire = trim($request->request->get('commentaire', ''));

        // Le motif de refus est obligatoire
        if (empty($commentaire)) {
            $this->addFlash('error', 'Un motif de refus est obligatoire.');
            return $this->redirectToRoute('admin_kyc_view', ['id' => $id]);
        }

        $this->kycService->refuseKyc($kyc, $commentaire);
        $this->notificationService->notifyKycRefused($kyc->getUser(), $commentaire);

        $this->addFlash('warning', "✘ KYC de {$kyc->getUser()->getFullName()} refusé.");

        return $this->redirectToRoute('admin_kyc_list');
    }
}
