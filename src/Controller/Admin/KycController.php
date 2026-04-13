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
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[IsGranted('ROLE_ADMIN')]
#[Route('/admin/kyc', name: 'admin_kyc_')]
class KycController extends AbstractController
{
    public function __construct(
        private readonly KycRepository $kycRepository,
        private readonly KycService $kycService,
        private readonly NotificationService $notificationService,
        private readonly ValidatorInterface $validator,
    ) {}

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

    #[Route('/{id}', name: 'view', methods: ['GET'])]
    public function view(int $id): Response
    {
        $kyc = $this->kycRepository->findWithFiles($id);

        if (!$kyc) {
            throw $this->createNotFoundException('Dossier KYC introuvable.');
        }

        $filesData = [];
        foreach ($kyc->getFiles() as $file) {
            $filesData[] = [
                'file' => $file,
                'path' => $this->kycService->getPublicFilePath($file),
            ];
        }

        return $this->render('admin/kyc/view.html.twig', [
            'kyc' => $kyc,
            'filesData' => $filesData,
        ]);
    }

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

        $this->addFlash('success', "✔ KYC de {$kyc->getUser()->getFullName()} approuve. Compte active.");

        return $this->redirectToRoute('admin_kyc_list');
    }

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

        $commentaire = trim((string) $request->request->get('commentaire', ''));
        $errors = $this->validator->validate($commentaire, [
            new Assert\NotBlank(['message' => 'Un motif de refus est obligatoire.']),
            new Assert\Length([
                'min' => 10,
                'minMessage' => 'Le motif de refus doit contenir au moins {{ limit }} caracteres.',
                'max' => 1000,
                'maxMessage' => 'Le motif de refus ne peut pas depasser {{ limit }} caracteres.',
            ]),
        ]);

        if (count($errors) > 0) {
            $this->addFlash('error', (string) $errors[0]->getMessage());

            return $this->redirectToRoute('admin_kyc_view', ['id' => $id]);
        }

        $this->kycService->refuseKyc($kyc, $commentaire);
        $this->notificationService->notifyKycRefused($kyc->getUser(), $commentaire);

        $this->addFlash('warning', "✘ KYC de {$kyc->getUser()->getFullName()} refuse.");

        return $this->redirectToRoute('admin_kyc_list');
    }
}
