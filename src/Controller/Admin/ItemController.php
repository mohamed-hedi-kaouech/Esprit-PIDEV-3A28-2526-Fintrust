<?php

namespace App\Controller\Admin;

use App\Entity\Categorie\Alerte;
use App\Entity\Categorie\Categorie;
use App\Entity\Categorie\Item;
use App\Form\Admin\ItemType;
use App\Repository\ItemRepository;
use App\Service\ItemExportService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/item', name: 'admin_item_')]
class ItemController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ItemExportService $itemExportService,
    ) {
    }

    private function createAlerteIfThresholdReached(Item $item, float $oldTotal, float $newTotal): void
    {
        $categorie = $item->getCategorie();
        $seuil = $categorie->getSeuilAlerte();

        if ($oldTotal < $seuil && $newTotal >= $seuil) {
            $alerte = new Alerte();
            $alerte->setCategorie($categorie);
            $alerte->setIdCategorie($categorie->getIdCategorie());
            $alerte->setSeuil($seuil);
            $alerte->setMessage(sprintf(
                'Le seuil d\'alerte de la categorie "%s" a ete atteint (%.2f DT / %.2f DT).',
                $categorie->getNomCategorie(),
                $newTotal,
                $seuil
            ));
            $alerte->setCreatedAt(new \DateTime());
            $alerte->setActive(true);
            $this->entityManager->persist($alerte);

            $this->addFlash('warning', 'Le seuil d\'alerte de la categorie "' . $categorie->getNomCategorie() . '" a ete atteint.');
        }
    }

    #[Route('', name: 'list', methods: ['GET'])]
    public function list(Request $request, ItemRepository $repository): Response
    {
        $search = $request->query->get('search', '');
        $categorieId = $request->query->get('categorie', '');
        $minAmount = $request->query->get('min_amount', '');
        $maxAmount = $request->query->get('max_amount', '');

        $queryBuilder = $repository->createQueryBuilder('i')
            ->leftJoin('i.categorie', 'c')
            ->addSelect('c');

        if (!empty($search)) {
            $queryBuilder->andWhere('i.libelle LIKE :search')
                ->setParameter('search', '%' . $search . '%');
        }

        if (!empty($categorieId)) {
            $queryBuilder->andWhere('i.idCategorie = :categorieId')
                ->setParameter('categorieId', $categorieId);
        }

        if (!empty($minAmount)) {
            $queryBuilder->andWhere('i.montant >= :minAmount')
                ->setParameter('minAmount', (float) $minAmount);
        }

        if (!empty($maxAmount)) {
            $queryBuilder->andWhere('i.montant <= :maxAmount')
                ->setParameter('maxAmount', (float) $maxAmount);
        }

        $items = $queryBuilder->orderBy('i.idItem', 'DESC')->getQuery()->getResult();

        $groupedItems = [];
        foreach ($items as $item) {
            $catName = $item->getCategorie()->getNomCategorie();
            $groupedItems[$catName][] = $item;
        }

        $categories = $this->entityManager->getRepository(Categorie::class)->findAll();

        return $this->render('admin/item/list.html.twig', [
            'items' => $items,
            'groupedItems' => $groupedItems,
            'categories' => $categories,
            'search' => $search,
            'categorieId' => $categorieId,
            'minAmount' => $minAmount,
            'maxAmount' => $maxAmount,
        ]);
    }

    #[Route('/show/{idItem}', name: 'show', methods: ['GET'])]
    public function show(Item $item): Response
    {
        return $this->render('admin/item/show.html.twig', [
            'item' => $item,
        ]);
    }

    #[Route('/create', name: 'create', methods: ['GET', 'POST'])]
    public function create(Request $request, ItemRepository $repository): Response
    {
        $item = new Item();
        $form = $this->createForm(ItemType::class, $item);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $categorie = $item->getCategorie();
            $existingTotal = $repository->getTotalMontantByCategorie($categorie->getIdCategorie());
            $newTotal = $existingTotal + $item->getMontant();

            if ($newTotal > $categorie->getBudgetPrevu()) {
                $form->get('montant')->addError(new FormError('La somme des montants des items de cette categorie depasse le budget prevu de la categorie.'));
            } else {
                $this->createAlerteIfThresholdReached($item, $existingTotal, $newTotal);
                $this->entityManager->persist($item);
                $this->entityManager->flush();

                $this->addFlash('success', 'Item cree avec succes!');
                return $this->redirectToRoute('admin_item_list');
            }
        }

        return $this->render('admin/item/create.html.twig', [
            'form' => $form,
        ]);
    }

    #[Route('/edit/{idItem}', name: 'edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, Item $item, ItemRepository $repository): Response
    {
        $form = $this->createForm(ItemType::class, $item);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $categorie = $item->getCategorie();
            $existingTotal = $repository->getTotalMontantByCategorie($categorie->getIdCategorie(), $item->getIdItem());
            $newTotal = $existingTotal + $item->getMontant();

            if ($newTotal > $categorie->getBudgetPrevu()) {
                $form->get('montant')->addError(new FormError('La somme des montants des items de cette categorie depasse le budget prevu de la categorie.'));
            } else {
                $this->createAlerteIfThresholdReached($item, $existingTotal, $newTotal);
                $this->entityManager->flush();

                $this->addFlash('success', 'Item modifie avec succes!');
                return $this->redirectToRoute('admin_item_list');
            }
        }

        return $this->render('admin/item/edit.html.twig', [
            'form' => $form,
            'item' => $item,
        ]);
    }

    #[Route('/bulk-delete', name: 'bulk_delete', methods: ['POST'])]
    public function bulkDelete(Request $request): Response
    {
        $itemIds = $request->request->all('items');

        if (empty($itemIds)) {
            $this->addFlash('error', 'Aucun item selectionne.');
            return $this->redirectToRoute('admin_item_list');
        }

        if (!$this->isCsrfTokenValid('bulk_delete', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Token CSRF invalide.');
            return $this->redirectToRoute('admin_item_list');
        }

        $items = $this->entityManager->getRepository(Item::class)->findBy(['idItem' => $itemIds]);
        $deletedCount = 0;

        foreach ($items as $item) {
            $this->entityManager->remove($item);
            ++$deletedCount;
        }

        $this->entityManager->flush();

        $this->addFlash('success', sprintf('%d item(s) supprime(s) avec succes!', $deletedCount));
        return $this->redirectToRoute('admin_item_list');
    }

    #[Route('/delete/{idItem}', name: 'delete', methods: ['POST'])]
    public function delete(Request $request, Item $item): Response
    {
        if (!$this->isCsrfTokenValid('delete' . $item->getIdItem(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Token CSRF invalide.');
            return $this->redirectToRoute('admin_item_list');
        }

        $this->entityManager->remove($item);
        $this->entityManager->flush();

        $this->addFlash('success', 'Item supprime avec succes!');
        return $this->redirectToRoute('admin_item_list');
    }

    #[Route('/export', name: 'export', methods: ['GET'])]
    public function export(Request $request, ItemRepository $repository): Response
    {
        $search = $request->query->get('search', '');
        $categorieId = $request->query->get('categorie', '');
        $minAmount = $request->query->get('min_amount', '');
        $maxAmount = $request->query->get('max_amount', '');

        $queryBuilder = $repository->createQueryBuilder('i')
            ->leftJoin('i.categorie', 'c')
            ->addSelect('c');

        if (!empty($search)) {
            $queryBuilder->andWhere('i.libelle LIKE :search')
                ->setParameter('search', '%' . $search . '%');
        }

        if (!empty($categorieId)) {
            $queryBuilder->andWhere('i.idCategorie = :categorieId')
                ->setParameter('categorieId', $categorieId);
        }

        if (!empty($minAmount)) {
            $queryBuilder->andWhere('i.montant >= :minAmount')
                ->setParameter('minAmount', (float) $minAmount);
        }

        if (!empty($maxAmount)) {
            $queryBuilder->andWhere('i.montant <= :maxAmount')
                ->setParameter('maxAmount', (float) $maxAmount);
        }

        /** @var Item[] $items */
        $items = $queryBuilder->orderBy('i.idItem', 'DESC')->getQuery()->getResult();
        $selectedCategory = null;

        if ($categorieId !== '') {
            $selectedCategory = $this->entityManager
                ->getRepository(Categorie::class)
                ->find((int) $categorieId);
        }

        return $this->itemExportService->exportItemsPdf($items, [
            'search' => $search,
            'categorieId' => $categorieId,
            'categorieLabel' => $selectedCategory instanceof Categorie ? $selectedCategory->getNomCategorie() : '',
            'minAmount' => $minAmount,
            'maxAmount' => $maxAmount,
        ]);
    }

    #[Route('/export/csv', name: 'export_csv', methods: ['GET'])]
    public function exportCsv(Request $request, ItemRepository $repository): StreamedResponse
    {
        $search = $request->query->get('search', '');
        $categorieId = $request->query->get('categorie', '');
        $minAmount = $request->query->get('min_amount', '');
        $maxAmount = $request->query->get('max_amount', '');

        $queryBuilder = $repository->createQueryBuilder('i')
            ->leftJoin('i.categorie', 'c')
            ->addSelect('c');

        if (!empty($search)) {
            $queryBuilder->andWhere('i.libelle LIKE :search')
                ->setParameter('search', '%' . $search . '%');
        }

        if (!empty($categorieId)) {
            $queryBuilder->andWhere('i.idCategorie = :categorieId')
                ->setParameter('categorieId', $categorieId);
        }

        if (!empty($minAmount)) {
            $queryBuilder->andWhere('i.montant >= :minAmount')
                ->setParameter('minAmount', (float) $minAmount);
        }

        if (!empty($maxAmount)) {
            $queryBuilder->andWhere('i.montant <= :maxAmount')
                ->setParameter('maxAmount', (float) $maxAmount);
        }

        /** @var Item[] $items */
        $items = $queryBuilder->orderBy('i.idItem', 'DESC')->getQuery()->getResult();

        $response = new StreamedResponse(function () use ($items): void {
            $handle = fopen('php://output', 'wb');
            if ($handle === false) {
                return;
            }

            fprintf($handle, chr(0xEF) . chr(0xBB) . chr(0xBF));
            fputcsv($handle, ['ID', 'Libelle', 'Categorie', 'Montant', 'Budget categorie', 'Seuil alerte'], ';');

            foreach ($items as $item) {
                $categorie = $item->getCategorie();
                fputcsv($handle, [
                    $item->getIdItem(),
                    $item->getLibelle(),
                    $categorie->getNomCategorie(),
                    number_format($item->getMontant(), 2, '.', ''),
                    number_format($categorie->getBudgetPrevu(), 2, '.', ''),
                    number_format($categorie->getSeuilAlerte(), 2, '.', ''),
                ], ';');
            }

            fclose($handle);
        });

        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="items_' . date('Y-m-d_H-i-s') . '.csv"');

        return $response;
    }
}
