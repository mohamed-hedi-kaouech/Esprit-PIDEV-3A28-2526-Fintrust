<?php

namespace App\Controller\Front;

use App\Entity\Categorie\Alerte;
use App\Entity\Categorie\Categorie;
use App\Entity\Categorie\Item;
use App\Form\Admin\CategorieType;
use App\Form\Admin\ItemType;
use App\Repository\CategorieRepository;
use App\Repository\ItemRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_CLIENT')]
#[Route('/espace-client/budget', name: 'front_budget_')]
class BudgetController extends AbstractController
{
    private const CATEGORY_PRESETS = [
        'logement' => [
            'name' => 'Logement',
            'budget' => 1200.0,
            'threshold' => 950.0,
            'icon' => 'bi-house-door',
            'description' => 'Loyer, syndic, entretien et depenses de residence.',
        ],
        'alimentation' => [
            'name' => 'Alimentation',
            'budget' => 650.0,
            'threshold' => 520.0,
            'icon' => 'bi-basket2',
            'description' => 'Courses, supermarche, repas et depenses du quotidien.',
        ],
        'transport' => [
            'name' => 'Transport',
            'budget' => 350.0,
            'threshold' => 280.0,
            'icon' => 'bi-car-front',
            'description' => 'Carburant, taxi, transport public et entretien auto.',
        ],
        'factures' => [
            'name' => 'Factures & abonnements',
            'budget' => 300.0,
            'threshold' => 240.0,
            'icon' => 'bi-receipt',
            'description' => 'Internet, telephone, electricite, eau et services recurrents.',
        ],
        'sante' => [
            'name' => 'Sante',
            'budget' => 250.0,
            'threshold' => 190.0,
            'icon' => 'bi-heart-pulse',
            'description' => 'Consultations, pharmacie, assurance et prevention.',
        ],
        'loisirs' => [
            'name' => 'Loisirs',
            'budget' => 220.0,
            'threshold' => 175.0,
            'icon' => 'bi-controller',
            'description' => 'Sorties, streaming, sport et moments detente.',
        ],
        'epargne' => [
            'name' => 'Epargne',
            'budget' => 500.0,
            'threshold' => 450.0,
            'icon' => 'bi-piggy-bank',
            'description' => 'Montants reserves, objectifs et discipline d epargne.',
        ],
    ];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly CategorieRepository $categorieRepository,
        private readonly ItemRepository $itemRepository,
    ) {
    }

    #[Route('', name: 'home', methods: ['GET'])]
    public function home(): Response
    {
        $categories = $this->categorieRepository->searchByFilters('', null, null, null, 'usage');
        $alertRepository = $this->entityManager->getRepository(Alerte::class);
        $latestAlerts = $alertRepository->findBy([], ['createdAt' => 'DESC'], 5);
        $allAlerts = $alertRepository->findBy([], ['createdAt' => 'ASC']);

        $budgetStats = [];
        $totalBudget = 0.0;
        $totalSpent = 0.0;
        $totalItems = 0;
        $activeAlerts = 0;

        foreach ($categories as $categorie) {
            $spent = $this->itemRepository->getTotalMontantByCategorie($categorie->getIdCategorie());
            $budget = $categorie->getBudgetPrevu();
            $remaining = $budget - $spent;
            $usage = $budget > 0 ? ($spent / $budget) * 100 : 0.0;
            $itemCount = $categorie->getItems()->count();
            $alertCount = count(array_filter(
                $categorie->getAlertes()->toArray(),
                static fn (Alerte $alerte): bool => $alerte->getActive() === true
            ));

            if ($usage >= 100 || $alertCount > 0) {
                $status = 'danger';
                $statusLabel = 'Sous tension';
            } elseif ($usage >= 80 || $spent >= $categorie->getSeuilAlerte()) {
                $status = 'warning';
                $statusLabel = 'Vigilance';
            } else {
                $status = 'success';
                $statusLabel = 'Stable';
            }

            $budgetStats[] = [
                'categorie' => $categorie,
                'budget' => $budget,
                'spent' => $spent,
                'remaining' => $remaining,
                'usage' => $usage,
                'itemCount' => $itemCount,
                'alertCount' => $alertCount,
                'status' => $status,
                'statusLabel' => $statusLabel,
            ];

            $totalBudget += $budget;
            $totalSpent += $spent;
            $totalItems += $itemCount;
            $activeAlerts += $alertCount;
        }

        usort($budgetStats, static fn (array $left, array $right): int => $right['spent'] <=> $left['spent']);
        $topCategories = array_slice($budgetStats, 0, 3);
        $remainingBudget = $totalBudget - $totalSpent;
        $globalUsage = $totalBudget > 0 ? ($totalSpent / $totalBudget) * 100 : 0.0;

        $weeklyActivity = [];
        $today = new \DateTimeImmutable('today');
        for ($i = 6; $i >= 0; --$i) {
            $day = $today->modify('-' . $i . ' days');
            $key = $day->format('d/m');
            $weeklyActivity[$key] = 0;
        }

        foreach ($allAlerts as $alerte) {
            $key = $alerte->getCreatedAt()->format('d/m');
            if (array_key_exists($key, $weeklyActivity)) {
                ++$weeklyActivity[$key];
            }
        }

        $trackedCategories = count($budgetStats);
        $insight = 'Commencez par creer vos categories budgetaires pour activer une lecture plus utile de vos depenses.';

        if ($topCategories !== []) {
            $leadingCategory = $topCategories[0];
            $insight = sprintf(
                'La categorie %s concentre actuellement %s TND de depenses, soit %s%% de son budget. Surveillez cette zone en priorite.',
                $leadingCategory['categorie']->getNomCategorie(),
                number_format($leadingCategory['spent'], 2, ',', ' '),
                number_format($leadingCategory['usage'], 1, ',', ' ')
            );
        }

        return $this->render('front/client/budget.html.twig', [
            'budgetStats' => $budgetStats,
            'topCategories' => $topCategories,
            'latestAlerts' => $latestAlerts,
            'weeklyActivity' => $weeklyActivity,
            'totalBudget' => $totalBudget,
            'totalSpent' => $totalSpent,
            'remainingBudget' => $remainingBudget,
            'totalItems' => $totalItems,
            'trackedCategories' => $trackedCategories,
            'activeAlerts' => $activeAlerts,
            'globalUsage' => $globalUsage,
            'insight' => $insight,
        ]);
    }

    #[Route('/categories', name: 'categories', methods: ['GET'])]
    public function categories(Request $request): Response
    {
        $search = trim((string) $request->query->get('search', ''));
        $entities = $this->categorieRepository->searchByFilters($search, null, null, null, 'nom');
        $categories = array_map(
            fn (Categorie $categorie): array => $this->buildCategoryCardData($categorie),
            $entities
        );
        $existingNames = array_map(
            static fn (Categorie $categorie): string => mb_strtolower(trim($categorie->getNomCategorie())),
            $entities
        );
        $suggestedCategories = array_values(array_filter(
            $this->getCategoryPresets(),
            static fn (array $preset): bool => !in_array(mb_strtolower($preset['name']), $existingNames, true)
        ));

        return $this->render('front/client/budget/categories.html.twig', [
            'categories' => $categories,
            'search' => $search,
            'suggestedCategories' => $suggestedCategories,
        ]);
    }

    #[Route('/categories/create', name: 'category_create', methods: ['GET', 'POST'])]
    public function createCategory(Request $request): Response
    {
        $categorie = new Categorie();
        $presetSlug = trim((string) $request->query->get('preset', ''));
        if ($presetSlug !== '') {
            $preset = $this->getCategoryPreset($presetSlug);
            if ($preset !== null) {
                $categorie->setNomCategorie($preset['name']);
                $categorie->setBudgetPrevu($preset['budget']);
                $categorie->setSeuilAlerte($preset['threshold']);
            }
        }

        $form = $this->createForm(CategorieType::class, $categorie);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if ($categorie->getSeuilAlerte() >= $categorie->getBudgetPrevu()) {
                $this->addFlash('error', 'Le seuil d alerte doit etre inferieur au budget prevu.');
            } elseif ($this->categoryNameExists($categorie->getNomCategorie())) {
                $this->addFlash('error', 'Une categorie avec ce nom existe deja.');
            } else {
                $this->entityManager->persist($categorie);
                $this->entityManager->flush();
                $this->addFlash('success', 'Categorie creee avec succes.');

                return $this->redirectToRoute('front_budget_categories');
            }
        }

        return $this->render('front/client/budget/category_form.html.twig', [
            'form' => $form->createView(),
            'is_edit' => false,
            'categorie' => null,
            'categoryPresets' => $this->getCategoryPresets(),
            'selectedPreset' => $presetSlug !== '' ? $this->getCategoryPreset($presetSlug) : null,
        ]);
    }

    #[Route('/categories/preset/{slug}/create', name: 'category_create_preset', methods: ['POST'])]
    public function createPresetCategory(string $slug, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('front_budget_create_preset_' . $slug, (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Action invalide.');

            return $this->redirectToRoute('front_budget_categories');
        }

        $preset = $this->getCategoryPreset($slug);
        if ($preset === null) {
            $this->addFlash('error', 'Categorie suggeree introuvable.');

            return $this->redirectToRoute('front_budget_categories');
        }

        if ($this->categoryNameExists($preset['name'])) {
            $this->addFlash('warning', 'Cette categorie existe deja.');

            return $this->redirectToRoute('front_budget_categories');
        }

        $categorie = (new Categorie())
            ->setNomCategorie($preset['name'])
            ->setBudgetPrevu($preset['budget'])
            ->setSeuilAlerte($preset['threshold']);

        $this->entityManager->persist($categorie);
        $this->entityManager->flush();
        $this->addFlash('success', 'Categorie "' . $preset['name'] . '" ajoutee avec succes.');

        return $this->redirectToRoute('front_budget_category_show', [
            'idCategorie' => $categorie->getIdCategorie(),
        ]);
    }

    #[Route('/categories/{idCategorie}', name: 'category_show', methods: ['GET'])]
    public function showCategory(Categorie $categorie): Response
    {
        $spent = $this->itemRepository->getTotalMontantByCategorie($categorie->getIdCategorie());
        $usage = $categorie->getBudgetPrevu() > 0 ? ($spent / $categorie->getBudgetPrevu()) * 100 : 0;
        $alerts = array_values(array_filter(
            $categorie->getAlertes()->toArray(),
            static fn (Alerte $alerte): bool => $alerte->getActive() === true
        ));
        $items = $categorie->getItems()->toArray();
        usort($items, static fn (Item $left, Item $right): int => $right->getIdItem() <=> $left->getIdItem());
        $averageItemAmount = count($items) > 0 ? array_sum(array_map(static fn (Item $item): float => $item->getMontant(), $items)) / count($items) : 0.0;
        $largestItem = $items !== []
            ? array_reduce($items, static function (?Item $carry, Item $item): Item {
                if ($carry === null || $item->getMontant() > $carry->getMontant()) {
                    return $item;
                }

                return $carry;
            })
            : null;

        [$statusClass, $statusLabel] = $this->resolveBudgetStatus($usage, count($alerts), $spent, $categorie->getSeuilAlerte());

        return $this->render('front/client/budget/category_show.html.twig', [
            'categorie' => $categorie,
            'items' => $items,
            'spent' => $spent,
            'remaining' => $categorie->getBudgetPrevu() - $spent,
            'usage' => $usage,
            'activeAlerts' => $alerts,
            'statusClass' => $statusClass,
            'statusLabel' => $statusLabel,
            'thresholdRemaining' => max(0, $categorie->getSeuilAlerte() - $spent),
            'averageItemAmount' => $averageItemAmount,
            'largestItem' => $largestItem,
        ]);
    }

    #[Route('/categories/{idCategorie}/edit', name: 'category_edit', methods: ['GET', 'POST'])]
    public function editCategory(Request $request, Categorie $categorie): Response
    {
        $form = $this->createForm(CategorieType::class, $categorie);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if ($categorie->getSeuilAlerte() >= $categorie->getBudgetPrevu()) {
                $this->addFlash('error', 'Le seuil d alerte doit etre inferieur au budget prevu.');
            } else {
                $this->entityManager->flush();
                $this->addFlash('success', 'Categorie modifiee avec succes.');

                return $this->redirectToRoute('front_budget_category_show', [
                    'idCategorie' => $categorie->getIdCategorie(),
                ]);
            }
        }

        return $this->render('front/client/budget/category_form.html.twig', [
            'form' => $form->createView(),
            'is_edit' => true,
            'categorie' => $categorie,
        ]);
    }

    #[Route('/categories/{idCategorie}/delete', name: 'category_delete', methods: ['POST'])]
    public function deleteCategory(Request $request, Categorie $categorie): Response
    {
        if (!$this->isCsrfTokenValid('front_budget_delete_category_' . $categorie->getIdCategorie(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Action invalide.');

            return $this->redirectToRoute('front_budget_categories');
        }

        if (!$categorie->getItems()->isEmpty()) {
            $this->addFlash('error', 'Impossible de supprimer cette categorie car elle contient des items.');

            return $this->redirectToRoute('front_budget_category_show', [
                'idCategorie' => $categorie->getIdCategorie(),
            ]);
        }

        $this->entityManager->remove($categorie);
        $this->entityManager->flush();
        $this->addFlash('success', 'Categorie supprimee avec succes.');

        return $this->redirectToRoute('front_budget_categories');
    }

    #[Route('/items/create', name: 'item_create', methods: ['GET', 'POST'])]
    public function createItem(Request $request): Response
    {
        $item = new Item();
        $preselectedCategoryId = $request->query->getInt('categorie', 0);
        if ($preselectedCategoryId > 0) {
            $categorie = $this->categorieRepository->find($preselectedCategoryId);
            if ($categorie instanceof Categorie) {
                $item->setCategorie($categorie);
                $item->setIdCategorie($categorie->getIdCategorie());
                $item->setCategorieLabel($categorie->getNomCategorie());
            }
        }

        $form = $this->createForm(ItemType::class, $item);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $categorie = $item->getCategorie();
            $existingTotal = $this->itemRepository->getTotalMontantByCategorie($categorie->getIdCategorie());
            $newTotal = $existingTotal + $item->getMontant();

            if ($newTotal > $categorie->getBudgetPrevu()) {
                $form->get('montant')->addError(new FormError('La somme des items depasse le budget prevu de cette categorie.'));
            } else {
                $this->synchronizeItemCategory($item);
                $this->createAlerteIfThresholdReached($item, $existingTotal, $newTotal);
                $this->entityManager->persist($item);
                $this->entityManager->flush();
                $this->addFlash('success', 'Item cree avec succes.');

                return $this->redirectToRoute('front_budget_category_show', [
                    'idCategorie' => $categorie->getIdCategorie(),
                ]);
            }
        }

        return $this->render('front/client/budget/item_form.html.twig', [
            'form' => $form->createView(),
            'item' => null,
            'is_edit' => false,
        ]);
    }

    #[Route('/items/{idItem}', name: 'item_show', methods: ['GET'])]
    public function showItem(Item $item): Response
    {
        $categoryTotal = $this->itemRepository->getTotalMontantByCategorie($item->getCategorie()->getIdCategorie());
        $percentage = $categoryTotal > 0 ? ($item->getMontant() / $categoryTotal) * 100 : 0;

        return $this->render('front/client/budget/item_show.html.twig', [
            'item' => $item,
            'categoryTotal' => $categoryTotal,
            'percentage' => $percentage,
        ]);
    }

    #[Route('/items/{idItem}/edit', name: 'item_edit', methods: ['GET', 'POST'])]
    public function editItem(Request $request, Item $item): Response
    {
        $form = $this->createForm(ItemType::class, $item);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $categorie = $item->getCategorie();
            $existingTotal = $this->itemRepository->getTotalMontantByCategorie($categorie->getIdCategorie(), $item->getIdItem());
            $newTotal = $existingTotal + $item->getMontant();

            if ($newTotal > $categorie->getBudgetPrevu()) {
                $form->get('montant')->addError(new FormError('La somme des items depasse le budget prevu de cette categorie.'));
            } else {
                $this->synchronizeItemCategory($item);
                $this->createAlerteIfThresholdReached($item, $existingTotal, $newTotal);
                $this->entityManager->flush();
                $this->addFlash('success', 'Item modifie avec succes.');

                return $this->redirectToRoute('front_budget_item_show', [
                    'idItem' => $item->getIdItem(),
                ]);
            }
        }

        return $this->render('front/client/budget/item_form.html.twig', [
            'form' => $form->createView(),
            'item' => $item,
            'is_edit' => true,
        ]);
    }

    #[Route('/items/{idItem}/delete', name: 'item_delete', methods: ['POST'])]
    public function deleteItem(Request $request, Item $item): Response
    {
        $categoryId = $item->getCategorie()->getIdCategorie();

        if (!$this->isCsrfTokenValid('front_budget_delete_item_' . $item->getIdItem(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Action invalide.');

            return $this->redirectToRoute('front_budget_item_show', [
                'idItem' => $item->getIdItem(),
            ]);
        }

        $this->entityManager->remove($item);
        $this->entityManager->flush();
        $this->addFlash('success', 'Item supprime avec succes.');

        return $this->redirectToRoute('front_budget_category_show', [
            'idCategorie' => $categoryId,
        ]);
    }

    private function synchronizeItemCategory(Item $item): void
    {
        $categorie = $item->getCategorie();
        $item->setIdCategorie($categorie->getIdCategorie());
        $item->setCategorieLabel($categorie->getNomCategorie());
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
                'Le seuil d alerte de la categorie "%s" a ete atteint (%.2f / %.2f TND).',
                $categorie->getNomCategorie(),
                $newTotal,
                $seuil
            ));
            $alerte->setCreatedAt(new \DateTime());
            $alerte->setActive(true);
            $this->entityManager->persist($alerte);

            $this->addFlash('warning', 'Le seuil d alerte de la categorie "' . $categorie->getNomCategorie() . '" a ete atteint.');
        }
    }

    /**
     * @return array{
     *     entity: Categorie,
     *     idCategorie: int,
     *     nomCategorie: string,
     *     budgetPrevu: float,
     *     seuilAlerte: float,
     *     spent: float,
     *     remaining: float,
     *     itemCount: int,
     *     alertCount: int,
     *     usage: float,
     *     statusLabel: string,
     *     statusClass: string,
     *     sparkline: list<float>
     * }
     */
    private function buildCategoryCardData(Categorie $categorie): array
    {
        $items = $categorie->getItems()->toArray();
        usort(
            $items,
            static fn (Item $left, Item $right): int => $left->getIdItem() <=> $right->getIdItem()
        );

        $budget = $categorie->getBudgetPrevu();
        $spent = $this->itemRepository->getTotalMontantByCategorie($categorie->getIdCategorie());
        $remaining = $budget - $spent;
        $usage = $budget > 0 ? ($spent / $budget) * 100 : 0.0;
        $itemCount = count($items);
        $alertCount = count(array_filter(
            $categorie->getAlertes()->toArray(),
            static fn (Alerte $alerte): bool => $alerte->getActive() === true
        ));

        if ($usage >= 100 || $alertCount > 0) {
            $statusLabel = 'Critique';
            $statusClass = 'danger';
        } elseif ($usage >= 80 || $spent >= $categorie->getSeuilAlerte()) {
            $statusLabel = 'Vigilance';
            $statusClass = 'warning';
        } else {
            $statusLabel = 'OK';
            $statusClass = 'ok';
        }

        $sparkline = [0.0];
        $runningTotal = 0.0;

        foreach ($items as $item) {
            $runningTotal += $item->getMontant();
            $sparkline[] = $budget > 0 ? min(100.0, round(($runningTotal / $budget) * 100, 2)) : 0.0;
        }

        $sparkline = array_slice($sparkline, -6);
        while (count($sparkline) < 6) {
            array_unshift($sparkline, 0.0);
        }

        return [
            'entity' => $categorie,
            'idCategorie' => $categorie->getIdCategorie(),
            'nomCategorie' => $categorie->getNomCategorie(),
            'budgetPrevu' => $budget,
            'seuilAlerte' => $categorie->getSeuilAlerte(),
            'spent' => $spent,
            'remaining' => $remaining,
            'itemCount' => $itemCount,
            'alertCount' => $alertCount,
            'usage' => $usage,
            'statusLabel' => $statusLabel,
            'statusClass' => $statusClass,
            'sparkline' => $sparkline,
        ];
    }

    /**
     * @return list<array{slug:string,name:string,budget:float,threshold:float,icon:string,description:string}>
     */
    private function getCategoryPresets(): array
    {
        $presets = [];
        foreach (self::CATEGORY_PRESETS as $slug => $preset) {
            $presets[] = [
                'slug' => $slug,
                'name' => $preset['name'],
                'budget' => $preset['budget'],
                'threshold' => $preset['threshold'],
                'icon' => $preset['icon'],
                'description' => $preset['description'],
            ];
        }

        return $presets;
    }

    /**
     * @return array{slug:string,name:string,budget:float,threshold:float,icon:string,description:string}|null
     */
    private function getCategoryPreset(string $slug): ?array
    {
        if (!isset(self::CATEGORY_PRESETS[$slug])) {
            return null;
        }

        return ['slug' => $slug] + self::CATEGORY_PRESETS[$slug];
    }

    private function categoryNameExists(string $name): bool
    {
        $normalized = mb_strtolower(trim($name));

        foreach ($this->categorieRepository->findAll() as $existing) {
            if (mb_strtolower(trim($existing->getNomCategorie())) === $normalized) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{0:string,1:string}
     */
    private function resolveBudgetStatus(float $usage, int $alertCount, float $spent, float $threshold): array
    {
        if ($usage >= 100 || $alertCount > 0) {
            return ['danger', 'Sous tension'];
        }

        if ($usage >= 80 || $spent >= $threshold) {
            return ['warning', 'Vigilance'];
        }

        return ['success', 'Stable'];
    }
}
