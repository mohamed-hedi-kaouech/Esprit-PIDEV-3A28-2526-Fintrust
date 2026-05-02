<?php

namespace App\Service\Voice;

use Symfony\Component\HttpFoundation\RequestStack;

class VoiceSessionService
{
    public function __construct(
        private readonly RequestStack $requestStack,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function get(string $sessionId): array
    {
        $session = $this->requestStack->getSession();
        $store = $session->get('fintrust_voice_sessions', []);

        return is_array($store[$sessionId] ?? null) ? $store[$sessionId] : [
            'session_id' => $sessionId,
            'turns' => [],
            'state' => 'started',
        ];
    }

    /**
     * @param array<string, mixed> $commandResult
     * @return array<string, mixed>
     */
    public function append(string $sessionId, string $userInput, array $commandResult): array
    {
        $session = $this->requestStack->getSession();
        $store = $session->get('fintrust_voice_sessions', []);
        $conversation = $this->get($sessionId);

        $turns = is_array($conversation['turns'] ?? null) ? $conversation['turns'] : [];
        $turns[] = [
            'input' => $userInput,
            'intent' => $commandResult['intent'] ?? VoiceIntentResolverService::UNKNOWN,
            'response_text' => $commandResult['response_text'] ?? '',
            'created_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ];

        $conversation['turns'] = array_slice($turns, -8);
        $conversation['state'] = [
            'last_intent' => $commandResult['intent'] ?? VoiceIntentResolverService::UNKNOWN,
            'language' => $commandResult['language'] ?? 'fr',
            'last_action' => $commandResult['action'] ?? null,
            'turn_count' => count($conversation['turns']),
        ];

        $store[$sessionId] = $conversation;
        $session->set('fintrust_voice_sessions', $store);

        return $conversation;
    }
}
