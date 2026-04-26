<?php

namespace App\Controller\Admin;

use App\Entity\Categorie\Categorie;
use App\Form\Admin\CategorieType;
use App\Repository\CategorieRepository;
use App\Repository\ItemRepository;
use App\Service\RewardService;
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
    public function list(CategorieRepository $repository): Response
    {
        return $this->render('admin/categorie/list.html.twig', [
            'categories' => $repository->findAll(),
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

            $this->addFlash('success', 'Categorie creee avec succes.');

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
            $this->addFlash('success', 'Categorie modifiee avec succes.');

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
        $categories = $this->entityManager->getRepository(Categorie::class)->findAll();
        $stats = [];
        $categoryLabels = [];
        $budgetSeries = [];
        $spentSeries = [];
        $thresholdSeries = [];
        $remainingSeries = [];
        $usageSeries = [];
        $itemSeries = [];
        $alertSeries = [];
        $healthSeries = [];

        $totalBudget = 0.0;
        $totalSpent = 0.0;
        $totalItems = 0;
        $totalAlertes = 0;
        $overThresholdCount = 0;
        $criticalCount = 0;
        $healthyCount = 0;
        $vigilanceCount = 0;
        $zeroSpendCount = 0;

        foreach ($categories as $categorie) {
            $totalAmount = $itemRepository->getTotalMontantByCategorie($categorie->getIdCategorie());
            $itemCount = $this->entityManager->getRepository(\App\Entity\Categorie\Item::class)
                ->count(['idCategorie' => $categorie->getIdCategorie()]);
            $alertesCount = $this->entityManager->getRepository(\App\Entity\Categorie\Alerte::class)
                ->count(['idCategorie' => $categorie->getIdCategorie(), 'active' => true]);
            $budget = (float) $categorie->getBudgetPrevu();
            $threshold = (float) $categorie->getSeuilAlerte();
            $remaining = $budget - $totalAmount;
            $budgetUsage = $budget > 0 ? ($totalAmount / $budget) * 100 : 0.0;
            $thresholdUsage = $threshold > 0 ? ($totalAmount / $threshold) * 100 : 0.0;
            $healthScore = max(0, min(100, 100 - ($budgetUsage * 0.65) - ($alertesCount * 18) + min(20, $itemCount * 4)));

            if ($totalAmount <= 0.0) {
                ++$zeroSpendCount;
            }

            if ($totalAmount >= $threshold) {
                ++$overThresholdCount;
            }

            if ($budgetUsage >= 90 || $alertesCount > 0) {
                ++$criticalCount;
                $status = 'critical';
            } elseif ($budgetUsage >= 65 || $thresholdUsage >= 90) {
                ++$vigilanceCount;
                $status = 'warning';
            } else {
                ++$healthyCount;
                $status = 'healthy';
            }

            $stats[] = [
                'categorie' => $categorie,
                'totalAmount' => $totalAmount,
                'itemCount' => $itemCount,
                'alertesCount' => $alertesCount,
                'budgetUsage' => $budgetUsage,
                'remaining' => $remaining,
                'thresholdUsage' => $thresholdUsage,
                'healthScore' => $healthScore,
                'status' => $status,
            ];

            $categoryLabels[] = $categorie->getNomCategorie();
            $budgetSeries[] = round($budget, 2);
            $spentSeries[] = round($totalAmount, 2);
            $thresholdSeries[] = round($threshold, 2);
            $remainingSeries[] = round($remaining, 2);
            $usageSeries[] = round($budgetUsage, 1);
            $itemSeries[] = $itemCount;
            $alertSeries[] = $alertesCount;
            $healthSeries[] = round($healthScore, 1);

            $totalBudget += $budget;
            $totalSpent += $totalAmount;
            $totalItems += $itemCount;
            $totalAlertes += $alertesCount;
        }

        usort($stats, static fn (array $left, array $right): int => $right['budgetUsage'] <=> $left['budgetUsage']);

        $categoryCount = count($stats);
        $globalUsage = $totalBudget > 0 ? ($totalSpent / $totalBudget) * 100 : 0.0;
        $remainingBudget = $totalBudget - $totalSpent;
        $averageBudget = $categoryCount > 0 ? $totalBudget / $categoryCount : 0.0;
        $averageSpend = $categoryCount > 0 ? $totalSpent / $categoryCount : 0.0;
        $averageUsage = $categoryCount > 0 ? array_sum($usageSeries) / $categoryCount : 0.0;
        $averageHealth = $categoryCount > 0 ? array_sum($healthSeries) / $categoryCount : 0.0;
        $engagementPerItem = $totalItems > 0 ? $totalSpent / $totalItems : 0.0;
        $topSpender = $stats[0] ?? null;
        $bestManaged = null;

        if ($stats !== []) {
            $bestManagedCandidates = $stats;
            usort(
                $bestManagedCandidates,
                static fn (array $left, array $right): int => $right['healthScore'] <=> $left['healthScore']
            );
            $bestManaged = $bestManagedCandidates[0] ?? null;
        }

        $rewardSnapshot = $rewardService->buildRewardSnapshot();
        $hasActiveAlerts = $rewardSnapshot['alertCategories'] !== [];
        $hasThresholdBreaches = $rewardSnapshot['thresholdCategories'] !== [] || $rewardSnapshot['budgetOverflowCategories'] !== [];
        $isEligible = $rewardSnapshot['isEligible'];

        $rewardReason = 'Respectez votre budget total et les seuils de chaque categorie pour debloquer la recompense.';
        if ($isEligible) {
            $rewardReason = 'Toutes les categories restent sous leur seuil d alerte et aucune alerte active ne bloque la recompense.';
        } elseif ($rewardSnapshot['blockedCategories'] !== []) {
            $rewardReason = sprintf(
                'Recompense non debloquee : %s.',
                implode(', ', array_slice($rewardSnapshot['blockedCategories'], 0, 4))
            );
        }

        $riskyCategories = array_slice(
            array_values(array_filter($stats, static fn (array $stat): bool => $stat['status'] !== 'healthy')),
            0,
            4
        );

        $rewardChecklist = [
            [
                'label' => 'Aucune alerte active',
                'ok' => !$hasActiveAlerts,
                'detail' => $hasActiveAlerts
                    ? sprintf('%d categorie(s) encore alertees.', count($rewardSnapshot['alertCategories']))
                    : 'Toutes les alertes sont inactives.',
            ],
            [
                'label' => 'Aucun seuil depasse',
                'ok' => $rewardSnapshot['thresholdCategories'] === [],
                'detail' => $rewardSnapshot['thresholdCategories'] !== []
                    ? implode(', ', array_slice($rewardSnapshot['thresholdCategories'], 0, 3))
                    : 'Chaque categorie reste sous sa zone d alerte.',
            ],
            [
                'label' => 'Aucun budget depasse',
                'ok' => $rewardSnapshot['budgetOverflowCategories'] === [],
                'detail' => $rewardSnapshot['budgetOverflowCategories'] !== []
                    ? implode(', ', array_slice($rewardSnapshot['budgetOverflowCategories'], 0, 3))
                    : 'Aucun budget prevu n a ete depasse.',
            ],
            [
                'label' => 'Categories conformes',
                'ok' => $rewardSnapshot['safeCount'] === $rewardSnapshot['categoriesChecked'] && $rewardSnapshot['categoriesChecked'] > 0,
                'detail' => sprintf(
                    '%d/%d categorie(s) conformes.',
                    $rewardSnapshot['safeCount'],
                    $rewardSnapshot['categoriesChecked']
                ),
            ],
        ];

        return $this->render('admin/categorie/stats.html.twig', [
            'stats' => $stats,
            'isEligible' => $isEligible,
            'rewardReason' => $rewardReason,
            'hasActiveAlerts' => $hasActiveAlerts,
            'hasThresholdBreaches' => $hasThresholdBreaches,
            'rewardSnapshot' => $rewardSnapshot,
            'rewardChecklist' => $rewardChecklist,
            'riskyCategories' => $riskyCategories,
            'overview' => [
                'totalBudget' => $totalBudget,
                'totalSpent' => $totalSpent,
                'totalItems' => $totalItems,
                'totalAlertes' => $totalAlertes,
                'remainingBudget' => $remainingBudget,
                'globalUsage' => $globalUsage,
                'averageBudget' => $averageBudget,
                'averageSpend' => $averageSpend,
                'averageUsage' => $averageUsage,
                'averageHealth' => $averageHealth,
                'engagementPerItem' => $engagementPerItem,
                'overThresholdCount' => $overThresholdCount,
                'criticalCount' => $criticalCount,
                'healthyCount' => $healthyCount,
                'vigilanceCount' => $vigilanceCount,
                'zeroSpendCount' => $zeroSpendCount,
                'topSpender' => $topSpender,
                'bestManaged' => $bestManaged,
            ],
            'chartData' => [
                'labels' => $categoryLabels,
                'budgets' => $budgetSeries,
                'spent' => $spentSeries,
                'thresholds' => $thresholdSeries,
                'remaining' => $remainingSeries,
                'usage' => $usageSeries,
                'items' => $itemSeries,
                'alerts' => $alertSeries,
                'health' => $healthSeries,
                'statusBreakdown' => [$healthyCount, $vigilanceCount, $criticalCount],
            ],
        ]);
    }

    #[Route('/send-reward-sms', name: 'send_reward_sms', methods: ['POST'])]
    public function sendRewardSms(Request $request, RewardService $rewardService): Response
    {
        if (!$this->isCsrfTokenValid('send_reward_sms', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Requete invalide.');

            return $this->redirectToRoute('admin_categorie_stats');
        }

        /** @var \App\Entity\User\User $user */
        $user = $this->getUser();
        $rewardSnapshot = $rewardService->buildRewardSnapshot();

        if (!$user->getNumTel()) {
            $this->addFlash('warning', 'Aucun numero de telephone enregistre sur votre compte.');

            return $this->redirectToRoute('admin_categorie_stats');
        }

        if (!$rewardSnapshot['isEligible']) {
            $this->addFlash('info', 'La recompense reste bloquee tant qu une categorie est alertee ou qu un seuil est depasse.');

            return $this->redirectToRoute('admin_categorie_stats');
        }

        if ($rewardService->grantReward($user)) {
            $this->addFlash('success', 'Felicitations ! Un SMS avec votre code promo a ete envoye au ' . $user->getNumTel());
        } else {
            $this->addFlash('error', 'Erreur lors de l envoi du SMS. Verifiez votre numero de telephone.');
        }

        return $this->redirectToRoute('admin_categorie_stats');
    }

    #[Route('/delete/{idCategorie}', name: 'delete', methods: ['POST'])]
    public function delete(Request $request, Categorie $categorie): Response
    {
        if ($request->isMethod('POST')) {
            if ($this->isCsrfTokenValid('delete' . $categorie->getIdCategorie(), $request->request->get('_token'))) {
                if (!$categorie->getItems()->isEmpty()) {
                    $this->addFlash('error', 'Impossible de supprimer cette categorie car elle contient des items. Supprimez d abord les items associes.');

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

    #[Route('/pdf', name: 'pdf', methods: ['GET'])]
    public function downloadPdf(\App\Service\BudgetInvoiceService $budgetInvoiceService): Response
    {
        $user = $this->getUser();
        $pdfContent = $budgetInvoiceService->generate(
            $user instanceof \App\Entity\User\User ? $user : null
        );

        $filename = 'facture_budget_' . date('Ymd_His') . '.pdf';

        return new Response($pdfContent, Response::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            'Content-Length' => strlen($pdfContent),
        ]);
    }
}
