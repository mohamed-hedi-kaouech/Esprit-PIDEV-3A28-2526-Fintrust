<?php

namespace App\Service;

use App\Entity\User\User;
use App\Repository\UserRepository;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class CommentModerationService
{
    /**
     * Expressions haineuses, racistes, violentes ou extremement injurieuses.
     * Un match ici doit bloquer le commentaire meme sans API externe.
     *
     * @var list<string>
     */
    private const SEVERE_PATTERNS = [
        '/\b(encul(?:e|ee|ees|es)?|nique ta mere|ntm|fdp|fils de pute)\b/iu',
        '/\b(sale\s+(noir|noire|arabe|juif|juive|blanc|blanche|immigre|immigree)s?)\b/iu',
        '/\b(sous[- ]race|race inferieure|race de|espece de)\b/iu',
        '/\b(heil hitler|nazi|mort aux)\b/iu',
        '/\b(retourne dans ton pays|dehors les immigres?|ces gens-la ne meritent pas)\b/iu',
        '/\b(il faut\s+(les|vous)?\s*(tuer|eliminer|degager)|je vais te tuer|va crever|creve)\b/iu',
        '/\b(je deteste les\s+[[:alpha:]\p{L}\- ]+|ne meritent? pas de vivre(?: ici)?)\b/iu',
    ];

    /**
     * Vulgarites, insultes directes ou indirectes a moderer.
     *
     * @var list<string>
     */
    private const MODERATE_PATTERNS = [
        '/\b(con|connard|connasse|idiot|idiote|imbecile|debile|abruti|abrutie)\b/iu',
        '/\b(merde|putain|pute|salope|batard|ta gueule)\b/iu',
        '/\b(ks|kes|zebi|tebe[nm]?k|nayek)\b/iu',
        '/\b(vous etes nul+l?s?|vous etes des nul+l?s?|t[\' ]?es nul+l?|tu es nul+l?|bande de nul+l?s?)\b/iu',
        '/\b(sale type|sale mec|sale meuf|pauvre type|pauvre con|gros nul+l?)\b/iu',
        '/\b(tu sers a rien|tu ne sers a rien|vous servez a rien|ferme-la|degage)\b/iu',
        '/\b(j[\' ]?te deteste|je vous deteste|vraiment nul+l?)\b/iu',
    ];

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly UserRepository $userRepository,
        private readonly NotificationService $notificationService,
        private readonly ?string $openAiApiKey,
        private readonly ?string $openAiModerationModel,
    ) {
    }

    /**
     * @return array{
     *   is_toxic: bool,
     *   toxicity_score: float,
     *   categories: array{insult: bool, hate: bool, racism: bool, aggressive: bool, toxic: bool, disrespect: bool},
     *   severity: string,
     *   decision: string,
     *   message: string,
     *   provider: string
     * }
     */
    public function analyzeComment(string $text): array
    {
        $normalizedText = trim($text);

        if ($normalizedText === '') {
            return $this->buildAcceptedResult();
        }

        if (trim((string) $this->openAiApiKey) === '') {
            return $this->buildKeywordModerationResult($normalizedText, 'Configuration OpenAI absente');
        }

        try {
            $response = $this->httpClient->request('POST', 'https://api.openai.com/v1/moderations', [
                'headers' => [
                    'Authorization' => 'Bearer ' . (string) $this->openAiApiKey,
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'model' => $this->getConfiguredModerationModel(),
                    'input' => $normalizedText,
                ],
                'timeout' => 20,
            ]);

            $payload = $response->toArray(false);
            $result = $payload['results'][0] ?? null;

            if (!is_array($result)) {
                return $this->buildKeywordModerationResult($normalizedText, 'Reponse moderation invalide');
            }

            $categories = is_array($result['categories'] ?? null) ? $result['categories'] : [];
            $categoryScores = is_array($result['category_scores'] ?? null) ? $result['category_scores'] : [];

            $mappedCategories = [
                'insult' => (bool) ($categories['harassment'] ?? false) || (bool) ($categories['harassment/threatening'] ?? false),
                'hate' => (bool) ($categories['hate'] ?? false) || (bool) ($categories['hate/threatening'] ?? false),
                'racism' => (bool) ($categories['hate'] ?? false) || (bool) ($categories['hate/threatening'] ?? false),
                'aggressive' => (bool) ($categories['harassment/threatening'] ?? false) || (bool) ($categories['violence'] ?? false),
                'toxic' => (bool) ($result['flagged'] ?? false),
                'disrespect' => (bool) ($categories['harassment'] ?? false),
            ];

            $toxicityScore = max([
                (float) ($categoryScores['harassment'] ?? 0),
                (float) ($categoryScores['harassment/threatening'] ?? 0),
                (float) ($categoryScores['hate'] ?? 0),
                (float) ($categoryScores['hate/threatening'] ?? 0),
                (float) ($categoryScores['violence'] ?? 0),
                (float) ($categoryScores['violence/graphic'] ?? 0),
            ]);

            $decision = 'accept';
            $severity = 'low';
            $message = 'Commentaire acceptable';

            $isSevere = $mappedCategories['hate']
                || $mappedCategories['racism']
                || (bool) ($categories['hate/threatening'] ?? false)
                || $toxicityScore >= 0.75;

            $isBorderline = !$isSevere && (
                $toxicityScore >= 0.60
                || ($mappedCategories['insult'] && $toxicityScore >= 0.20)
                || ($mappedCategories['disrespect'] && $toxicityScore >= 0.25)
                || ($mappedCategories['aggressive'] && $toxicityScore >= 0.35)
            );

            if ($isSevere) {
                $decision = 'reject';
                $severity = 'high';
                $message = 'Contenu inapproprie detecte';
            } elseif ($isBorderline) {
                $decision = 'moderate';
                $severity = 'medium';
                $message = 'Contenu douteux a moderer';
            } else {
                $keywordFallback = $this->buildKeywordModerationResult($normalizedText, 'Controle local complementaire');
                if ($keywordFallback['decision'] !== 'accept') {
                    return $keywordFallback;
                }
            }

            return [
                'is_toxic' => $decision !== 'accept',
                'toxicity_score' => round($toxicityScore, 4),
                'categories' => $mappedCategories,
                'severity' => $severity,
                'decision' => $decision,
                'message' => $message,
                'provider' => 'openai:' . $this->getConfiguredModerationModel(),
            ];
        } catch (ExceptionInterface|\Throwable) {
            return $this->buildKeywordModerationResult($normalizedText, 'Service moderation indisponible');
        }
    }

    public function handleDecision(string $text, User $author, string $contextLabel): array
    {
        $analysis = $this->analyzeComment($text);

        if ($analysis['decision'] === 'moderate') {
            $this->notifyAdminsForModeration($author, $text, $contextLabel, $analysis);
        }

        return $analysis;
    }

    private function getConfiguredModerationModel(): string
    {
        $model = trim((string) $this->openAiModerationModel);

        return $model !== '' ? $model : 'omni-moderation-latest';
    }

    /**
     * @param array{
     *   is_toxic: bool,
     *   toxicity_score: float,
     *   categories: array{insult: bool, hate: bool, racism: bool, aggressive: bool, toxic: bool, disrespect: bool},
     *   severity: string,
     *   decision: string,
     *   message: string,
     *   provider: string
     * } $analysis
     */
    private function notifyAdminsForModeration(User $author, string $text, string $contextLabel, array $analysis): void
    {
        $admins = $this->userRepository->findBy([
            'role' => User::ROLE_ADMIN,
            'status' => User::STATUS_ACTIF,
        ]);

        $excerpt = mb_substr(trim($text), 0, 140);
        $message = sprintf(
            'Moderation commentaire | Auteur: %s | Contexte: %s | Score: %.2f | Extrait: %s',
            $author->getFullName(),
            $contextLabel,
            $analysis['toxicity_score'],
            $excerpt
        );

        foreach ($admins as $admin) {
            if ($admin instanceof User) {
                $this->notificationService->notify($admin, $message, 'WARNING');
            }
        }
    }

    /**
     * @return array{
     *   is_toxic: bool,
     *   toxicity_score: float,
     *   categories: array{insult: bool, hate: bool, racism: bool, aggressive: bool, toxic: bool, disrespect: bool},
     *   severity: string,
     *   decision: string,
     *   message: string,
     *   provider: string
     * }
     */
    private function buildAcceptedResult(): array
    {
        return [
            'is_toxic' => false,
            'toxicity_score' => 0.0,
            'categories' => [
                'insult' => false,
                'hate' => false,
                'racism' => false,
                'aggressive' => false,
                'toxic' => false,
                'disrespect' => false,
            ],
            'severity' => 'none',
            'decision' => 'accept',
            'message' => 'Commentaire acceptable',
            'provider' => 'none',
        ];
    }

    /**
     * @return array{
     *   is_toxic: bool,
     *   toxicity_score: float,
     *   categories: array{insult: bool, hate: bool, racism: bool, aggressive: bool, toxic: bool, disrespect: bool},
     *   severity: string,
     *   decision: string,
     *   message: string,
     *   provider: string
     * }
     */
    private function buildKeywordModerationResult(string $text, string $reason): array
    {
        $normalizedText = mb_strtolower(trim($text));

        foreach (self::SEVERE_PATTERNS as $pattern) {
            if (preg_match($pattern, $normalizedText) === 1) {
                return [
                    'is_toxic' => true,
                    'toxicity_score' => 0.95,
                    'categories' => [
                        'insult' => true,
                        'hate' => true,
                        'racism' => true,
                        'aggressive' => true,
                        'toxic' => true,
                        'disrespect' => true,
                    ],
                    'severity' => 'high',
                    'decision' => 'reject',
                    'message' => 'Contenu haineux, violent ou gravement injurieux detecte',
                    'provider' => 'fallback-keywords:' . $reason,
                ];
            }
        }

        foreach (self::MODERATE_PATTERNS as $pattern) {
            if (preg_match($pattern, $normalizedText) === 1) {
                return [
                    'is_toxic' => true,
                    'toxicity_score' => 0.65,
                    'categories' => [
                        'insult' => true,
                        'hate' => false,
                        'racism' => false,
                        'aggressive' => true,
                        'toxic' => true,
                        'disrespect' => true,
                    ],
                    'severity' => 'medium',
                    'decision' => 'moderate',
                    'message' => 'Langage vulgaire, insultant ou agressif detecte',
                    'provider' => 'fallback-keywords:' . $reason,
                ];
            }
        }

        return [
            'is_toxic' => false,
            'toxicity_score' => 0.0,
            'categories' => [
                'insult' => false,
                'hate' => false,
                'racism' => false,
                'aggressive' => false,
                'toxic' => false,
                'disrespect' => false,
            ],
            'severity' => 'none',
            'decision' => 'accept',
            'message' => 'Commentaire acceptable',
            'provider' => 'fallback-keywords:' . $reason,
        ];
    }
}
