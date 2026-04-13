<?php

namespace App\Controller\Admin;

use App\Entity\Categorie\Categorie;
use App\Form\Admin\CategorieType;
use App\Repository\CategorieRepository;
use App\Repository\ItemRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/categorie', name: 'admin_categorie_')]
class CategorieController extends AbstractController
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    #[Route('', name: 'list', methods: ['GET'])]
    public function list(Request $request, CategorieRepository $repository): Response
    {
        $search = trim((string) $request->query->get('search', ''));
        $status = trim((string) $request->query->get('status', 'all'));
        $budgetRange = trim((string) $request->query->get('budget_range', 'all'));
        $itemsRange = trim((string) $request->query->get('items_range', 'all'));
        $sort = trim((string) $request->query->get('sort', 'nom'));

        if (!in_array($status, ['all', 'ok', 'danger', 'warning'], true)) {
            $status = 'all';
        }

        if (!in_array($budgetRange, ['all', '0-500', '500-1000', '1000+'], true)) {
            $budgetRange = 'all';
        }

        if (!in_array($itemsRange, ['all', '0', '1-5', '6+'], true)) {
            $itemsRange = 'all';
        }

        if (!in_array($sort, ['nom', 'budget', 'depenses', 'creation', 'usage', 'items'], true)) {
            $sort = 'nom';
        }

        $categories = $repository->searchByFilters(
            $search,
            $status === 'all' ? null : $status,
            $budgetRange === 'all' ? null : $budgetRange,
            $itemsRange === 'all' ? null : $itemsRange,
            $sort
        );

        return $this->render('admin/categorie/list.html.twig', [
            'categories' => $categories,
            'search' => $search,
            'status' => $status,
            'budget_range' => $budgetRange,
            'items_range' => $itemsRange,
            'sort' => $sort,
        ]);
    }

    #[Route('/show/{idCategorie}', name: 'show', methods: ['GET'])]
    public function show(Categorie $categorie): Response
    {
        return $this->render('admin/categorie/show.html.twig', [
            'categorie' => $categorie,
        ]);
    }

    #[Route('/{idCategorie}/items', name: 'items', methods: ['GET'])]
    public function items(Categorie $categorie): Response
    {
        return $this->render('admin/categorie/items.html.twig', [
            'categorie' => $categorie,
            'items' => $categorie->getItems(),
        ]);
    }

    #[Route('/create', name: 'create', methods: ['GET', 'POST'])]
    public function create(Request $request): Response
    {
        $categorie = new Categorie();
        $form = $this->createForm(CategorieType::class, $categorie);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if ($categorie->getSeuilAlerte() >= $categorie->getBudgetPrevu()) {
                $this->addFlash('error', 'Seuil invalide');
            } else {
                $this->entityManager->persist($categorie);
                $this->entityManager->flush();

                $this->addFlash('success', 'Categorie creee avec succes.');

                return $this->redirectToRoute('admin_categorie_list');
            }
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
            if ($categorie->getSeuilAlerte() >= $categorie->getBudgetPrevu()) {
                $this->addFlash('error', 'Seuil invalide');
            } else {
                $this->entityManager->flush();

                $this->addFlash('success', 'Categorie modifiee avec succes.');

                return $this->redirectToRoute('admin_categorie_list');
            }
        }

        return $this->render('admin/categorie/edit.html.twig', [
            'form' => $form,
            'categorie' => $categorie,
        ]);
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
    public function delete(Request $request, Categorie $categorie): Response
    {
        if ($request->isMethod('POST')) {
            if ($this->isCsrfTokenValid('delete' . $categorie->getIdCategorie(), $request->request->get('_token'))) {
                if (!$categorie->getItems()->isEmpty()) {
                    $this->addFlash('error', 'Impossible de supprimer cette categorie car elle contient des items.');

                    return $this->redirectToRoute('admin_categorie_list');
                }

                $this->entityManager->remove($categorie);
                $this->entityManager->flush();

                $this->addFlash('success', 'Categorie supprimee avec succes.');
            }

            return $this->redirectToRoute('admin_categorie_list');
        }

        return $this->render('admin/categorie/delete.html.twig', [
            'categorie' => $categorie,
        ]);
    }
}
