<?php

namespace App\Service;

use App\Entity\User\User;
use App\Repository\UserRepository;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class CommentModerationService
{
    /**
     * Expressions clairement haineuses, racistes ou extremement insultantes.
     * Un match ici doit bloquer le commentaire meme sans API externe.
     *
     * @var list<string>
     */
    private const SEVERE_PATTERNS = [
        '/\b(encul[ée]s?|encule|nique ta m[èe]re|ntm|fdp|fils de pute)\b/iu',
        '/\b(sale\s+(noir|noire|arab[e]?\b|juif|juive|blanc|blanche|immigr[ée]))\b/iu',
        '/\b(race de|esp[èe]ce de)\b/iu',
        '/\b(heil hitler|nazi|mort aux)\b/iu',
        '/\bje d[eé]teste les\s+[[:alpha:]\p{L}\- ]+\b/iu',
        '/\b(ne meritent? pas de vivre ici|ne meritent? pas de vivre)\b/iu',
    ];

    /**
     * Vulgarites et insultes a moderer sans tout bloquer.
     *
     * @var list<string>
     */
    private const MODERATE_PATTERNS = [
        '/\b(con|connard|connasse|idiot|idiote|imb[ée]cile|d[ée]bile|abruti|abrutie)\b/iu',
        '/\b(merde|putain|pute|salope|batard|b[âa]tard|ta gueule)\b/iu',
        '/\b(ks|kes|zebi|zebi|tebe[nm]?k|nayek)\b/iu',
        '/\b(vous etes nuls?|vous [eê]tes nuls?|t[\'’]?es nul|tu es nul|bande de nuls?)\b/iu',
        '/\b(j[\' ]?te deteste|je vous deteste)\b/iu',
    ];

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly UserRepository $userRepository,
        private readonly NotificationService $notificationService,
        private readonly string $openAiApiKey,
        private readonly string $openAiModerationModel,

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

        if ($this->openAiApiKey === '') {
            return $this->buildKeywordModerationResult($normalizedText, 'Configuration OpenAI absente');
        }

        try {
            $response = $this->httpClient->request('POST', 'https://api.openai.com/v1/moderations', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->openAiApiKey,
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'model' => $this->openAiModerationModel,
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
                'provider' => 'openai:' . $this->openAiModerationModel,
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
                    'message' => 'Contenu haineux ou gravement injurieux detecte',
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
                    'message' => 'Langage vulgaire ou insultant detecte',
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
