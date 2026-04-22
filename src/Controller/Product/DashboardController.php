<?php

namespace App\Controller\Product;

use App\Repository\Product\ProductRepository;
use App\Repository\Product\ProductSubscriptionRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class DashboardController extends AbstractController
{
    public function __construct(
        private ProductRepository $productRepo,
        private ProductSubscriptionRepository $subscriptionRepo,
    ) {
    }

    #[Route('/admin/dashboard', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('html/Product/Admin/Dashboard.html.twig');
    }

    #[Route('/admin/dashboard/data', name: 'data', methods: ['GET'])]
    public function data(): JsonResponse
    {
        $products = $this->productRepo->findAll();
        $subscriptions = $this->subscriptionRepo->findAll();

        $products = array_filter($products, fn ($p) => $p && $p->getPrice() !== null && $p->getCategory() !== null);
        $subscriptions = array_filter($subscriptions, fn ($s) => $s && $s->getProductObj() && $s->getClientUser());

        $totalProducts = count($products);
        $prices = array_map(fn ($p) => $p->getPrice(), $products);
        $avgPrice = $totalProducts ? array_sum($prices) / $totalProducts : 0;
        $minPrice = $totalProducts ? min($prices) : 0;
        $maxPrice = $totalProducts ? max($prices) : 0;

        $productsByCategory = [];
        foreach ($products as $product) {
            $category = $product->getCategory();
            $productsByCategory[$category] = ($productsByCategory[$category] ?? 0) + 1;
        }

        arsort($productsByCategory);
        $topCategory = $productsByCategory ? array_key_first($productsByCategory) : '-';
        $topCategoryCount = $productsByCategory[$topCategory] ?? 0;

        $newest = null;
        foreach ($products as $product) {
            if (!$newest || $product->getCreatedAt() > $newest->getCreatedAt()) {
                $newest = $product;
            }
        }

        $newestName = $newest ? $newest->getDescription() : '-';
        $newestDate = $newest ? $newest->getCreatedAt()->format('Y-m-d') : '-';

        $totalSubs = count($subscriptions);
        $activeSubs = 0;
        $suspendedSubs = 0;
        $closedSubs = 0;
        $draftSubs = 0;
        $totalRevenue = 0.0;
        $subsByType = [];
        $subsOverTime = [];
        $revenueByCategory = [];

        foreach ($subscriptions as $subscription) {
            $status = strtoupper($subscription->getStatus() ?? '');
            switch ($status) {
                case 'ACTIVE':
                    $activeSubs++;
                    $totalRevenue += $subscription->getProductObj()->getPrice() ?? 0;
                    break;
                case 'SUSPENDED':
                    $suspendedSubs++;
                    break;
                case 'CLOSED':
                    $closedSubs++;
                    break;
                default:
                    $draftSubs++;
            }

            $type = strtoupper($subscription->getType() ?? 'UNKNOWN');
            $subsByType[$type] = ($subsByType[$type] ?? 0) + 1;

            $date = $subscription->getSubscriptionDate();
            if ($date) {
                $monthKey = $date->format('M Y');
                $subsOverTime[$monthKey] = ($subsOverTime[$monthKey] ?? 0) + 1;
            }

            if ($status === 'ACTIVE') {
                $category = $subscription->getProductObj()->getCategory() ?? '-';
                if (!isset($revenueByCategory[$category])) {
                    $revenueByCategory[$category] = ['revenue' => 0, 'subs' => 0];
                }

                $revenueByCategory[$category]['revenue'] += $subscription->getProductObj()->getPrice() ?? 0;
                $revenueByCategory[$category]['subs']++;
            }
        }

        uksort($subsOverTime, fn ($a, $b) => strtotime("01 $a") <=> strtotime("01 $b"));

        return $this->json([
            'totalProducts' => $totalProducts,
            'avgPrice' => round($avgPrice, 2),
            'minPrice' => $minPrice,
            'maxPrice' => $maxPrice,
            'topCategory' => $topCategory,
            'topCategoryCount' => $topCategoryCount,
            'newestProduct' => $newestName,
            'newestDate' => $newestDate,
            'productsByCategory' => $productsByCategory,
            'totalSubs' => $totalSubs,
            'activeSubs' => $activeSubs,
            'suspendedSubs' => $suspendedSubs,
            'closedSubs' => $closedSubs,
            'draftSubs' => $draftSubs,
            'totalRevenue' => round($totalRevenue, 2),
            'subsByType' => $subsByType,
            'subsOverTime' => $subsOverTime,
            'revenueByCategory' => $revenueByCategory,
        ]);
    }

    #[Route('/send-report', name: 'send_report', methods: ['POST'])]
    public function sendEmailReport(): JsonResponse
    {
        return $this->json([
            'success' => false,
            'message' => 'L envoi d e-mails de rapport est desactive pour le moment.',
        ]);
    }
}
