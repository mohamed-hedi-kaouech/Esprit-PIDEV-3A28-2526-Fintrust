<?php

namespace App\Controller\Admin;

use App\Entity\Categorie\Categorie;
use App\Repository\ItemRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/categorie', name: 'admin_categorie_')]
class CategorieController extends AbstractController
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    #[Route('', name: 'list', methods: ['GET'])]
    public function list(): RedirectResponse
    {
        $this->addFlash('info', 'Le CRUD des categories n est plus disponible dans l administration.');

        return $this->redirectToRoute('admin_categorie_stats');
    }

    #[Route('/show/{idCategorie}', name: 'show', methods: ['GET'])]
    public function show(Categorie $categorie): RedirectResponse
    {
        $this->addFlash('info', 'Le detail des categories est desormais gere hors du back-office admin.');

        return $this->redirectToRoute('admin_categorie_stats');
    }

    #[Route('/{idCategorie}/items', name: 'items', methods: ['GET'])]
    public function items(Categorie $categorie): RedirectResponse
    {
        $this->addFlash('info', 'La navigation categorie -> items n est plus exposee dans l administration.');

        return $this->redirectToRoute('admin_item_list');
    }

    #[Route('/create', name: 'create', methods: ['GET', 'POST'])]
    public function create(): RedirectResponse
    {
        $this->addFlash('warning', 'La creation de categories se fait maintenant dans la partie client.');

        return $this->redirectToRoute('admin_categorie_stats');
    }

    #[Route('/edit/{idCategorie}', name: 'edit', methods: ['GET', 'POST'])]
    public function edit(Categorie $categorie): RedirectResponse
    {
        $this->addFlash('warning', 'La modification de categories n est plus disponible dans l administration.');

        return $this->redirectToRoute('admin_categorie_stats');
    }

    #[Route('/stats', name: 'stats', methods: ['GET'])]
    public function stats(ItemRepository $itemRepository): Response
    {
        $categories = $this->entityManager->getRepository(Categorie::class)->findAll();
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

        return $this->render('admin/categorie/stats.html.twig', [
            'stats' => $stats,
        ]);
    }

    #[Route('/delete/{idCategorie}', name: 'delete', methods: ['GET', 'POST'])]
    public function delete(Categorie $categorie): RedirectResponse
    {
        $this->addFlash('warning', 'La suppression de categories n est plus disponible dans l administration.');

        return $this->redirectToRoute('admin_categorie_stats');
    }
}
