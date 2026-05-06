<?php

namespace App\Controller\Api;

use App\Entity\User\User;
use App\Service\Voice\VoiceAssistantService;
use App\Service\Voice\VoiceSessionService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_CLIENT')]
#[Route('/api/v1/voice/assistant', name: 'api_v1_voice_assistant_')]
class VoiceConversationController extends AbstractController
{
    public function __construct(
        private readonly VoiceAssistantService $voiceAssistantService,
        private readonly VoiceSessionService $voiceSessionService,
    ) {
    }

    #[Route('/conversation', name: 'conversation', methods: ['POST'])]
    public function conversation(Request $request): JsonResponse
    {
        $payload = $this->decodePayload($request);
        $sessionId = trim((string) ($payload['session_id'] ?? ''));
        if ($sessionId === '') {
            $sessionId = 'voice_' . bin2hex(random_bytes(8));
        }

        $result = $this->voiceAssistantService->handleCommand($this->getAuthenticatedUser(), $payload);
        $conversation = $this->voiceSessionService->append($sessionId, (string) ($result['transcript'] ?? ''), $result);

        return $this->json([
            'session_id' => $sessionId,
            'conversation_state' => $conversation['state'],
            'turns' => $conversation['turns'],
            'result' => $result,
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
