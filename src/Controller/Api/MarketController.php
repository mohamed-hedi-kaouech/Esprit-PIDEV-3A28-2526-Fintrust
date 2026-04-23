<?php

namespace App\Controller\Api;

use App\Service\MarketWatchService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/market', name: 'api_market_')]
#[IsGranted('ROLE_CLIENT')]
class MarketController extends AbstractController
{
    public function __construct(
        private readonly MarketWatchService $marketWatchService,
    ) {}

    #[Route('/assets/{symbol}', name: 'asset_detail', methods: ['GET'])]
    public function assetDetail(string $symbol): JsonResponse
    {
        $asset = $this->marketWatchService->getAssetDetail($symbol);
        if (!$asset) {
            return $this->json(['message' => 'Actif introuvable.'], 404);
        }

        return $this->json($asset);
    }

    #[Route('/trending', name: 'trending', methods: ['GET'])]
    public function trending(): JsonResponse
    {
        $items = $this->marketWatchService->getTrending();

        return $this->json(['items' => $items, 'total' => count($items)]);
    }

    #[Route('/overview', name: 'overview', methods: ['GET'])]
    public function overview(): JsonResponse
    {
        return $this->json($this->marketWatchService->getMarketOverview());
    }
}
