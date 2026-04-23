<?php

namespace App\Controller\Api;

use App\Entity\User\User;
use App\Service\FinancialNewsService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/news', name: 'api_news_')]
#[IsGranted('ROLE_CLIENT')]
class NewsController extends AbstractController
{
    public function __construct(
        private readonly FinancialNewsService $financialNewsService,
    ) {}

    #[Route('/financial', name: 'financial', methods: ['GET'])]
    public function financial(Request $request): JsonResponse
    {
        $items = $this->financialNewsService->getFinancialNews([
            'category' => $request->query->get('category'),
            'country' => $request->query->get('country'),
            'keyword' => $request->query->get('keyword'),
            'source' => $request->query->get('source'),
            'limit' => $request->query->getInt('limit', 10),
        ]);

        return $this->json([
            'items' => $items,
            'total' => count($items),
        ]);
    }

    #[Route('/market', name: 'market', methods: ['GET'])]
    public function market(Request $request): JsonResponse
    {
        $items = $this->financialNewsService->getMarketNews($request->query->getInt('limit', 8));

        return $this->json(['items' => $items, 'total' => count($items)]);
    }

    #[Route('/banks', name: 'banks', methods: ['GET'])]
    public function banks(Request $request): JsonResponse
    {
        $items = $this->financialNewsService->getBankNews($request->query->getInt('limit', 8));

        return $this->json(['items' => $items, 'total' => count($items)]);
    }

    #[Route('/user-feed', name: 'user_feed', methods: ['GET'])]
    public function userFeed(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        $items = $this->financialNewsService->getUserFeed($user, $request->query->getInt('limit', 6));

        return $this->json(['items' => $items, 'total' => count($items)]);
    }

    #[Route('/{id}', name: 'detail', methods: ['GET'])]
    public function detail(string $id): JsonResponse
    {
        $article = $this->financialNewsService->getArticle($id);

        if (!$article) {
            return $this->json(['message' => 'Actualite introuvable.'], 404);
        }

        return $this->json($article);
    }

    #[Route('/summarize', name: 'summarize', methods: ['POST'])]
    public function summarize(Request $request): JsonResponse
    {
        $payload = $request->toArray();
        $content = (string) ($payload['content'] ?? '');

        if ($content === '') {
            return $this->json(['message' => 'Contenu absent.'], 422);
        }

        return $this->json($this->financialNewsService->summarizeArticle($content));
    }
}
