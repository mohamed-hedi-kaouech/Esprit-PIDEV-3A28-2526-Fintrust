<?php

namespace App\Controller\Api;

use App\Entity\User\User;
use App\Service\Voice\VoiceAssistantService;
use App\Service\Voice\VoiceLanguageService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_CLIENT')]
#[Route('/api/v1/voice', name: 'api_v1_voice_')]
class VoiceCommandController extends AbstractController
{
    public function __construct(
        private readonly VoiceAssistantService $voiceAssistantService,
        private readonly VoiceLanguageService $voiceLanguageService,
    ) {
    }

    #[Route('/command', name: 'command', methods: ['POST'])]
    public function command(Request $request): JsonResponse
    {
        $payload = $this->decodePayload($request);

        return $this->json($this->voiceAssistantService->handleCommand($this->getAuthenticatedUser(), $payload));
    }

    #[Route('/supported/languages', name: 'supported_languages', methods: ['GET'])]
    public function supportedLanguages(): JsonResponse
    {
        return $this->json([
            'languages' => array_values($this->voiceLanguageService->getSupportedLanguages()),
            'default' => 'fr',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function decodePayload(Request $request): array
    {
        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            $payload = $request->request->all();
        }

        return $payload;
    }

    private function getAuthenticatedUser(): User
    {
        /** @var User $user */
        $user = $this->getUser();

        return $user;
    }
}
