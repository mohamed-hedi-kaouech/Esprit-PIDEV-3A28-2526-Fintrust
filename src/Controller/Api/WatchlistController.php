<?php

namespace App\Controller\Api;

use App\Service\MarketWatchService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/watchlist', name: 'api_watchlist_')]
#[IsGranted('ROLE_CLIENT')]
class WatchlistController extends AbstractController
{
    public function __construct(
        private readonly MarketWatchService $marketWatchService,
    ) {}

    #[Route('', name: 'list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        $items = $this->marketWatchService->getWatchlist();

        return $this->json(['items' => $items, 'total' => count($items)]);
    }

    #[Route('', name: 'add', methods: ['POST'])]
    public function add(Request $request): JsonResponse
    {
        try {
            $payload = $request->toArray();
        } catch (\Throwable) {
            $payload = [];
        }

        $symbol = (string) ($payload['symbol'] ?? '');
        if ($symbol === '' || !$this->marketWatchService->addToWatchlist($symbol)) {
            return $this->json(['message' => 'Actif introuvable.'], 404);
        }

        return $this->json([
            'message' => 'Actif ajoute a la watchlist.',
            'items' => $this->marketWatchService->getWatchlist(),
        ], 201);
    }

    #[Route('/highlights', name: 'highlights', methods: ['GET'])]
    public function highlights(): JsonResponse
    {
        $items = $this->marketWatchService->getHighlights();

        return $this->json(['items' => $items, 'total' => count($items)]);
    }

    #[Route('/{symbol}', name: 'remove', methods: ['DELETE'])]
    public function remove(string $symbol): JsonResponse
    {
        $this->marketWatchService->removeFromWatchlist($symbol);

        return $this->json([
            'message' => 'Actif retire de la watchlist.',
            'items' => $this->marketWatchService->getWatchlist(),
        ]);
    }
}
