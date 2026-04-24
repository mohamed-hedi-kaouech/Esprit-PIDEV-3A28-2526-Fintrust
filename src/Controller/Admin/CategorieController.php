<?php

namespace App\Controller\Admin;

use App\Entity\Categorie\Categorie;
use App\Form\Admin\CategorieType;
use App\Repository\CategorieRepository;
use App\Repository\ItemRepository;
use App\Service\PdfGeneratorService;
use App\Service\RewardService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/categorie', name: 'admin_categorie_')]
class CategorieController extends AbstractController
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    #[Route('', name: 'list', methods: ['GET'])]
    public function list(CategorieRepository $repository): Response
    {
        $categories = $repository->findAll();

        return $this->render('admin/categorie/list.html.twig', [
            'categories' => $categories,
        ]);
    }

    #[Route('/show/{idCategorie}', name: 'show', methods: ['GET'])]
    public function show(Categorie $categorie): Response
    {
        return $this->render('admin/categorie/show.html.twig', [
            'categorie' => $categorie,
        ]);
    }

    #[Route('/create', name: 'create', methods: ['GET', 'POST'])]
    public function create(Request $request): Response
    {
        $categorie = new Categorie();
        $form = $this->createForm(CategorieType::class, $categorie);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->persist($categorie);
            $this->entityManager->flush();

            $this->addFlash('success', 'Catégorie créée avec succès!');
            return $this->redirectToRoute('admin_categorie_list');
        }

        return $this->render('admin/categorie/create.html.twig', [
            'form' => $form,
        ]);
    }

    #[Route('/edit/{idCategorie}', name: 'edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, Categorie $categorie): Response
    {
        $form = $this->createForm(CategorieType::class, $categorie);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->flush();

            $this->addFlash('success', 'Catégorie modifiée avec succès!');
            return $this->redirectToRoute('admin_categorie_list');
        }

        return $this->render('admin/categorie/edit.html.twig', [
            'form' => $form,
            'categorie' => $categorie,
        ]);
    }

    #[Route('/stats', name: 'stats', methods: ['GET'])]
    public function stats(ItemRepository $itemRepository, RewardService $rewardService): Response
    {
        $categories = $this->entityManager->getRepository(\App\Entity\Categorie\Categorie::class)->findAll();
        $stats = [];

        foreach ($categories as $categorie) {
            $totalAmount = $itemRepository->getTotalMontantByCategorie($categorie->getIdCategorie());
            $itemCount = $this->entityManager->getRepository(\App\Entity\Categorie\Item::class)
                ->count(['idCategorie' => $categorie->getIdCategorie()]);
            $alertesCount = $this->entityManager->getRepository(\App\Entity\Categorie\Alerte::class)
                ->count(['idCategorie' => $categorie->getIdCategorie(), 'active' => true]);

            $stats[] = [
                'categorie' => $categorie,
                'totalAmount' => $totalAmount,
                'itemCount' => $itemCount,
                'alertesCount' => $alertesCount,
                'budgetUsage' => $categorie->getBudgetPrevu() > 0 ? ($totalAmount / $categorie->getBudgetPrevu()) * 100 : 0,
            ];
        }

        /** @var \App\Entity\User\User $user */
        $user = $this->getUser();
        $isEligible = $rewardService->isEligibleForReward($user);

        return $this->render('admin/categorie/stats.html.twig', [
            'stats' => $stats,
            'isEligible' => $isEligible,
        ]);
    }

    #[Route('/send-reward-sms', name: 'send_reward_sms', methods: ['POST'])]
    public function sendRewardSms(Request $request, RewardService $rewardService): Response
    {
        if (!$this->isCsrfTokenValid('send_reward_sms', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Requête invalide.');
            return $this->redirectToRoute('admin_categorie_stats');
        }

        /** @var \App\Entity\User\User $user */
        $user = $this->getUser();

        if (!$user->getNumTel()) {
            $this->addFlash('warning', 'Aucun numéro de téléphone enregistré sur votre compte.');
            return $this->redirectToRoute('admin_categorie_stats');
        }

        if (!$rewardService->isEligibleForReward($user)) {
            $this->addFlash('info', 'Vous n\'êtes pas éligible à une récompense. Respectez votre budget et vos seuils de catégories.');
            return $this->redirectToRoute('admin_categorie_stats');
        }

        if ($rewardService->grantReward($user)) {
            $this->addFlash('success', '🎉 Félicitations ! Un SMS avec votre code promo a été envoyé au ' . $user->getNumTel());
        } else {
            $this->addFlash('error', 'Erreur lors de l\'envoi du SMS. Vérifiez votre numéro de téléphone.');
        }

        return $this->redirectToRoute('admin_categorie_stats');
    }

    #[Route('/delete/{idCategorie}', name: 'delete', methods: ['POST'])]
    public function delete(Request $request, Categorie $categorie): Response
    {
        if ($request->isMethod('POST')) {
            if ($this->isCsrfTokenValid('delete' . $categorie->getIdCategorie(), $request->request->get('_token'))) {
                // Vérifier si la catégorie a des items associés
                if (!$categorie->getItems()->isEmpty()) {
                    $this->addFlash('error', 'Impossible de supprimer cette catégorie car elle contient des items. Supprimez d\'abord les items associés.');
                    return $this->redirectToRoute('admin_categorie_list');
                }

                $this->entityManager->remove($categorie);
                $this->entityManager->flush();

                $this->addFlash('success', 'Catégorie supprimée avec succès!');
            }

            return $this->redirectToRoute('admin_categorie_list');
        }

        // Afficher la page de confirmation
        return $this->render('admin/categorie/delete.html.twig', [
            'categorie' => $categorie,
        ]);
    }

    #[Route('/pdf', name: 'pdf', methods: ['GET'])]
    public function downloadPdf(\App\Service\BudgetInvoiceService $budgetInvoiceService): Response
    {
        $user = $this->getUser();
        $pdfContent = $budgetInvoiceService->generate(
            $user instanceof \App\Entity\User\User ? $user : null
        );

        $filename = 'facture_budget_' . date('Ymd_His') . '.pdf';

        return new Response($pdfContent, Response::HTTP_OK, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            'Content-Length'      => strlen($pdfContent),
        ]);
    }
}
