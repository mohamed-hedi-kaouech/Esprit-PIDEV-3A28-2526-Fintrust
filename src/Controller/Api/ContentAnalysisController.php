<?php

namespace App\Controller\Api;

use App\Service\ContentAnalysisService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_CLIENT')]
#[Route('/api/publication', name: 'api_publication_')]
class ContentAnalysisController extends AbstractController
{
    #[Route('/analyze', name: 'analyze', methods: ['POST'])]
    public function analyze(Request $request, ContentAnalysisService $contentAnalysisService): JsonResponse
    {
        $payload = json_decode($request->getContent(), true);

        if (!is_array($payload)) {
            return $this->json([
                'message' => 'Le body JSON est invalide.',
            ], 400);
        }

        $content = trim((string) ($payload['content'] ?? ''));
        try {
            $analysis = $contentAnalysisService->analyze($content);

            return $this->json($analysis);
        } catch (\InvalidArgumentException $exception) {
            return $this->json([
                'message' => $exception->getMessage(),
            ], 400);
        } catch (\RuntimeException $exception) {
            return $this->json([
                'message' => $exception->getMessage(),
            ], 502);
        }
    }
}
