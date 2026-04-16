<?php

namespace App\Controller\Api;

use App\Service\CommentModerationService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_CLIENT')]
#[Route('/api/comment-moderation', name: 'api_comment_moderation_')]
class CommentModerationController extends AbstractController
{
    #[Route('/analyze', name: 'analyze', methods: ['POST'])]
    public function analyze(Request $request, CommentModerationService $commentModerationService): JsonResponse
    {
        $payload = json_decode($request->getContent(), true);
        $text = trim((string) (($payload['text'] ?? null) ?? $request->request->get('text', '')));

        if ($text === '') {
            return $this->json([
                'message' => 'Le texte a analyser est obligatoire.',
            ], 400);
        }

        return $this->json($commentModerationService->analyzeComment($text));
    }
}
