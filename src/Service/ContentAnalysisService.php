<?php

namespace App\Service;

use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class ContentAnalysisService
{
    private const MODEL = 'gpt-4o-mini';
    private const MAX_CONTENT_LENGTH = 5000;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly ?string $openAiApiKey,
    ) {
    }

    /**
     * @return array{
     *   summary: string,
     *   key_points: list<string>,
     *   reading_time: string,
     *   simplified: string
     * }
     */
    public function analyze(string $content): array
    {
        $normalizedContent = trim($content);

        if ($normalizedContent === '') {
            throw new \InvalidArgumentException('Le contenu ne peut pas etre vide.');
        }

        if (mb_strlen($normalizedContent) > self::MAX_CONTENT_LENGTH) {
            throw new \InvalidArgumentException(sprintf(
                'Le contenu ne peut pas depasser %d caracteres.',
                self::MAX_CONTENT_LENGTH
            ));
        }

        $readingTime = $this->estimateReadingTime($normalizedContent);

        if (trim((string) $this->openAiApiKey) === '') {
            return $this->buildFallbackAnalysis($normalizedContent, $readingTime);
        }

        try {
            $response = $this->httpClient->request('POST', 'https://api.openai.com/v1/chat/completions', [
                'headers' => [
                    'Authorization' => 'Bearer ' . (string) $this->openAiApiKey,
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'model' => self::MODEL,
                    'response_format' => ['type' => 'json_object'],
                    'messages' => [
                        [
                            'role' => 'system',
                            'content' => $this->buildSystemPrompt(),
                        ],
                        [
                            'role' => 'user',
                            'content' => $normalizedContent,
                        ],
                    ],
                    'temperature' => 0.3,
                    'max_tokens' => 700,
                ],
                'timeout' => 30,
            ]);

            $payload = $response->toArray(false);
            $rawContent = $payload['choices'][0]['message']['content'] ?? null;

            if (!is_string($rawContent) || trim($rawContent) === '') {
                throw new \RuntimeException('Reponse vide recue depuis le service d analyse.');
            }

            $decoded = json_decode($rawContent, true);
            if (!is_array($decoded)) {
                throw new \RuntimeException('Reponse JSON invalide recue depuis le service d analyse.');
            }

            return $this->normalizeAnalysisResult($decoded, $normalizedContent, $readingTime);
        } catch (ExceptionInterface|\JsonException|\Throwable) {
            return $this->buildFallbackAnalysis($normalizedContent, $readingTime);
        }
    }

    private function estimateReadingTime(string $content): string
    {
        $wordCount = str_word_count(strip_tags($content));
        $minutes = max(1, (int) ceil($wordCount / 200));

        return sprintf('%d min', $minutes);
    }

    private function buildSystemPrompt(): string
    {
        return <<<PROMPT
Tu analyses des publications fintech et bancaires pour des clients FinTrust.
Le ton doit etre professionnel, clair et facile a comprendre.
Evite le jargon complexe ou explique-le simplement.

Analyse le texte fourni et retourne exclusivement un JSON valide avec cette structure :
{
  "summary": "Resume clair en 1 phrase complete.",
  "key_points": ["Point cle 1.", "Point cle 2.", "Point cle 3."],
  "simplified": "Version simplifiee et accessible du contenu en 1 ou 2 phrases completes."
}

Contraintes :
- "summary" doit contenir une seule phrase complete de maximum 22 mots.
- "key_points" doit contenir exactement 3 points cles, tres courts, complets, de maximum 12 mots chacun.
- "simplified" doit etre differente du resume, plus pedagogique, simple, concise, en 1 ou 2 phrases completes.
- N utilise pas de phrases coupees, pas de points de suspension, pas de repetition.
- Ne retourne aucun texte hors JSON.
PROMPT;
    }

    /**
     * Fallback local pour garder la fonctionnalite disponible meme sans cle OpenAI.
     *
     * @return array{
     *   summary: string,
     *   key_points: list<string>,
     *   reading_time: string,
     *   simplified: string
     * }
     */
    private function buildFallbackAnalysis(string $content, string $readingTime): array
    {
        $cleanContent = trim(preg_replace('/\s+/', ' ', strip_tags($content)) ?? $content);
        $sentences = $this->extractSentences($cleanContent);
        $summary = $this->buildSummaryFromSentences($sentences);
        $keyPoints = $this->buildKeyPointsFromSentences($sentences, $summary);
        $simplified = $this->buildSimplifiedFromSentences($sentences, $summary);

        return [
            'summary' => $summary,
            'key_points' => $keyPoints,
            'reading_time' => $readingTime,
            'simplified' => $simplified,
        ];
    }

    private function simplifyContent(string $content): string
    {
        $simplified = $content;

        $replacements = [
            '/\btaux d[\' ]int[eé]r[eê]t\b/iu' => 'cout du credit',
            '/\bcapacit[eé] d[\' ]emprunt\b/iu' => 'montant que le client peut emprunter',
            '/\bvolatilit[eé]\b/iu' => 'variation rapide des prix',
            '/\bliquidit[eé]\b/iu' => 'argent disponible rapidement',
            '/\bdiversification\b/iu' => 'repartition des placements',
            '/\brendement\b/iu' => 'gain possible',
            '/\bplacement(s)?\b/iu' => 'investissement$1',
        ];

        foreach ($replacements as $pattern => $replacement) {
            $simplified = preg_replace($pattern, $replacement, $simplified) ?? $simplified;
        }
        return $simplified;
    }

    /**
     * @param array<string, mixed> $decoded
     * @return array{
     *   summary: string,
     *   key_points: list<string>,
     *   reading_time: string,
     *   simplified: string
     * }
     */
    private function normalizeAnalysisResult(array $decoded, string $originalContent, string $readingTime): array
    {
        $sentences = $this->extractSentences(trim(preg_replace('/\s+/', ' ', strip_tags($originalContent)) ?? $originalContent));

        $summary = $this->finalizeSentence((string) ($decoded['summary'] ?? ''));
        $simplified = $this->finalizeSentence((string) ($decoded['simplified'] ?? ''));
        $keyPoints = array_values(array_filter(
            array_map(
                fn(mixed $point): string => $this->finalizeSentence((string) $point),
                is_array($decoded['key_points'] ?? null) ? $decoded['key_points'] : []
            ),
            static fn(string $point): bool => $point !== ''
        ));

        if ($summary === '') {
            $summary = $this->buildSummaryFromSentences($sentences);
        }

        if ($simplified === '') {
            $simplified = $this->buildSimplifiedFromSentences($sentences, $summary);
        }

        if ($keyPoints === []) {
            $keyPoints = $this->buildKeyPointsFromSentences($sentences, $summary);
        }

        $normalizedKeyPoints = [];

        foreach ($keyPoints as $point) {
            $normalizedPoint = $this->finalizeSentence($point);

            if ($normalizedPoint === '' || $normalizedPoint === $summary || $this->isTooSimilar($normalizedPoint, $summary)) {
                continue;
            }

            $isDuplicate = false;
            foreach ($normalizedKeyPoints as $existingPoint) {
                if ($this->isTooSimilar($normalizedPoint, $existingPoint)) {
                    $isDuplicate = true;
                    break;
                }
            }

            if (!$isDuplicate) {
                $normalizedKeyPoints[] = $normalizedPoint;
            }
        }

        $keyPoints = $normalizedKeyPoints;

        if (count($keyPoints) < 3) {
            foreach ($this->buildKeyPointsFromSentences($sentences, $summary) as $fallbackPoint) {
                $alreadyPresent = false;

                foreach ($keyPoints as $existingPoint) {
                    if ($this->isTooSimilar($fallbackPoint, $existingPoint)) {
                        $alreadyPresent = true;
                        break;
                    }
                }

                if (!$alreadyPresent) {
                    $keyPoints[] = $fallbackPoint;
                }

                if (count($keyPoints) === 3) {
                    break;
                }
            }
        }

        if ($simplified === $summary) {
            $simplified = $this->buildSimplifiedFromSentences($sentences, $summary);
        }

        return [
            'summary' => $this->limitSentenceLength($summary, 22),
            'key_points' => array_slice($keyPoints, 0, 3),
            'reading_time' => $readingTime,
            'simplified' => $this->limitSentenceLength($simplified, 30),
        ];
    }

    /**
     * @return list<string>
     */
    private function extractSentences(string $text): array
    {
        $parts = preg_split('/(?<=[\.\!\?])\s+/', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $sentences = [];

        foreach ($parts as $part) {
            $sentence = $this->finalizeSentence($part);
            if ($sentence !== '') {
                $sentences[] = $sentence;
            }
        }

        return $sentences;
    }

    private function buildSummaryFromSentences(array $sentences): string
    {
        $first = $this->buildContextualSentence(
            $sentences[0] ?? '',
            'Cette publication presente une information financiere importante pour le client.'
        );

        return $this->limitSentenceLength($first, 24);
    }

    /**
     * @param list<string> $sentences
     * @return list<string>
     */
    private function buildKeyPointsFromSentences(array $sentences, string $summary): array
    {
        $points = [];

        foreach (array_slice($sentences, 1) as $sentence) {
            $candidate = $this->buildContextualSentence($sentence);
            if (
                $candidate === ''
                || $candidate === $summary
                || $this->isTooSimilar($candidate, $summary)
                || in_array($candidate, $points, true)
            ) {
                continue;
            }

            $points[] = $candidate;

            if (count($points) === 3) {
                break;
            }
        }

        if ($points === []) {
            $points[] = 'Le contenu apporte un conseil utile au client.';
        }

        if (count($points) < 3) {
            $fallbacks = [
                'Le sujet concerne une decision financiere importante.',
                'Le client doit comparer les options disponibles.',
                'Une lecture simple aide a mieux comprendre l enjeu.',
            ];

            foreach ($fallbacks as $fallback) {
                if (!in_array($fallback, $points, true) && !$this->isTooSimilar($fallback, $summary)) {
                    $points[] = $fallback;
                }

                if (count($points) === 3) {
                    break;
                }
            }
        }

        return array_slice($points, 0, 3);
    }

    /**
     * @param list<string> $sentences
     */
    private function buildSimplifiedFromSentences(array $sentences, string $summary): string
    {
        $base = implode(' ', array_slice($sentences, 1, 2));
        if (trim($base) === '') {
            $base = $summary;
        }

        $simplified = $this->simplifyContent($base);
        $simplifiedSentences = $this->extractSentences($simplified);
        $contextualSentences = [];

        foreach (array_slice($simplifiedSentences, 0, 2) as $sentence) {
            $contextual = $this->buildContextualSentence($sentence);
            if ($contextual !== '' && !$this->isTooSimilar($contextual, $summary)) {
                $contextualSentences[] = $contextual;
            }
        }

        $result = implode(' ', $contextualSentences);

        if ($result === '' || $result === $summary || $this->isTooSimilar($result, $summary)) {
            return 'En clair, cette publication explique simplement un point important pour mieux guider le client.';
        }

        return $this->limitSentenceLength($result, 32);
    }

    private function finalizeSentence(string $text): string
    {
        $normalized = trim(preg_replace('/\s+/', ' ', strip_tags($text)) ?? $text);
        $normalized = rtrim($normalized, ". \t\n\r\0\x0B");

        if ($normalized === '') {
            return '';
        }

        return $normalized . '.';
    }

    private function limitSentenceLength(string $sentence, int $maxWords): string
    {
        $sentence = $this->finalizeSentence($sentence);
        if ($sentence === '') {
            return '';
        }

        $clauses = preg_split('/,\s+|;\s+/u', rtrim($sentence, '.'), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        foreach ($clauses as $clause) {
            $candidate = $this->finalizeSentence($clause);
            $candidateWords = preg_split('/\s+/', rtrim($candidate, '.'), -1, PREG_SPLIT_NO_EMPTY) ?: [];

            if (count($candidateWords) >= 6 && count($candidateWords) <= $maxWords) {
                return $candidate;
            }
        }

        $plainSentence = rtrim($sentence, '.');
        $words = preg_split('/\s+/', $plainSentence, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if (count($words) <= $maxWords) {
            return $sentence;
        }

        $trimmed = implode(' ', array_slice($words, 0, $maxWords));

        return $this->finalizeSentence($trimmed);
    }

    private function buildContextualSentence(string $sentence, string $fallback = 'Cette publication met en avant un point important pour le client.'): string
    {
        $normalized = mb_strtolower(trim($sentence));

        if ($normalized === '') {
            return $fallback;
        }

        $patterns = [
            '/taux.+credit|cout.+credit|taux d/' => 'La hausse des taux augmente le cout des credits bancaires.',
            '/mensualit.+pret|mensualit.+credit/' => 'Les mensualites des credits peuvent devenir plus elevees pour les clients.',
            '/comparer.+offres|plusieurs offres/' => 'Comparer plusieurs offres permet de choisir un credit plus avantageux.',
            '/difference.+taux|legere difference/' => 'Une faible difference de taux peut alourdir le cout total du pret.',
            '/capacite.+remboursement|surendettement/' => 'Verifier sa capacite de remboursement aide a eviter le surendettement.',
            '/epargne.+attract|produits d epargne/' => 'La hausse des taux peut aussi rendre certains produits d epargne plus attractifs.',
            '/diversifier|diversification/' => 'Diversifier ses choix financiers aide a mieux gerer le risque.',
        ];

        foreach ($patterns as $pattern => $replacement) {
            if (preg_match($pattern, $normalized) === 1) {
                return $replacement;
            }
        }

        $clauses = preg_split('/,\s+|;\s+/u', $this->finalizeSentence($sentence), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        foreach ($clauses as $clause) {
            $candidate = $this->finalizeSentence($clause);
            $wordCount = count(preg_split('/\s+/', rtrim($candidate, '.'), -1, PREG_SPLIT_NO_EMPTY) ?: []);
            if ($wordCount >= 6 && $wordCount <= 20) {
                return $candidate;
            }
        }

        return $fallback;
    }

    private function isTooSimilar(string $left, string $right): bool
    {
        $leftWords = array_unique(preg_split('/\s+/', mb_strtolower(trim(preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $left) ?? $left)), -1, PREG_SPLIT_NO_EMPTY) ?: []);
        $rightWords = array_unique(preg_split('/\s+/', mb_strtolower(trim(preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $right) ?? $right)), -1, PREG_SPLIT_NO_EMPTY) ?: []);

        if ($leftWords === [] || $rightWords === []) {
            return false;
        }

        $common = array_intersect($leftWords, $rightWords);
        $overlapRatio = count($common) / max(1, min(count($leftWords), count($rightWords)));

        return $overlapRatio >= 0.7;
    }
}
