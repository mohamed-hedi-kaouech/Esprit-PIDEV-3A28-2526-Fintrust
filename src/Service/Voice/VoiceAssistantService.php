<?php

namespace App\Service\Voice;

use App\Entity\User\User;
use App\Entity\Wallet\Wallet;
use Doctrine\ORM\EntityManagerInterface;

class VoiceAssistantService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly VoiceIntentResolverService $intentResolver,
        private readonly VoiceLanguageService $languageService,
        private readonly VoiceResponseBuilderService $responseBuilder,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function handleCommand(User $user, array $payload): array
    {
        $transcript = $this->extractTranscript($payload);
        $language = $this->languageService->detectLanguage($transcript, isset($payload['language']) ? (string) $payload['language'] : null);
        $resolution = $this->intentResolver->resolve($transcript, $language);
        $wallet = $this->findWalletForUser($user);

        if (!$wallet instanceof Wallet) {
            return [
                'transcript' => $transcript,
                'intent' => $resolution['intent'],
                'entities' => $resolution['entities'],
                'action' => 'wallet.not_found',
                'response_text' => 'Aucun wallet n est disponible pour votre compte.',
                'audio_response' => [
                    'mode' => 'placeholder',
                    'mime_type' => 'text/plain',
                    'text' => 'Aucun wallet n est disponible pour votre compte.',
                ],
                'confidence' => $resolution['confidence'],
                'language' => $resolution['language'],
                'details' => [],
                'suggestions' => [],
            ];
        }

        $response = $this->responseBuilder->build(
            $wallet,
            $resolution['intent'],
            $resolution['entities'],
            $resolution['language']
        );

        return [
            'transcript' => $transcript,
            'intent' => $resolution['intent'],
            'entities' => $resolution['entities'],
            'action' => $response['action'],
            'response_text' => $response['response_text'],
            'audio_response' => $response['audio_response'],
            'confidence' => $resolution['confidence'],
            'language' => $resolution['language'],
            'details' => $response['details'],
            'suggestions' => $response['suggestions'],
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function extractTranscript(array $payload): string
    {
        foreach (['text', 'transcript', 'audio_transcript', 'command'] as $key) {
            if (isset($payload[$key]) && trim((string) $payload[$key]) !== '') {
                return trim((string) $payload[$key]);
            }
        }

        return '';
    }

    private function findWalletForUser(User $user): ?Wallet
    {
        /** @var Wallet|null $wallet */
        $wallet = $this->entityManager->getRepository(Wallet::class)
            ->createQueryBuilder('w')
            ->andWhere('w.user = :user OR w.idUser = :userId')
            ->setParameter('user', $user)
            ->setParameter('userId', $user->getId())
            ->orderBy('w.dateCreation', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $wallet;
    }
}
