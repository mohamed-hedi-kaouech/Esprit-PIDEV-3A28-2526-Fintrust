<?php

namespace App\Service;

use App\Entity\Wallet\Wallet;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class OpenAIWalletAnalysisService
{
    private const API_URL = 'https://api.openai.com/v1/responses';
    private const LOCAL_FALLBACK_MODEL = 'analyse-locale-wallet';
    private const REQUEST_TIMEOUT_SECONDS = 30;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly WalletAnalyticsService $walletAnalyticsService,
        private readonly AnomalyDetectionService $anomalyDetectionService,
        private readonly RiskScoringService $riskScoringService,
        private readonly WalletClassificationService $walletClassificationService,
        private readonly PredictionService $predictionService,
        private readonly LoggerInterface $logger,
        private readonly ?string $openAiApiKey,
        private readonly ?string $openAiModel,
    ) {
    }

    /**
     * @return array{
     *   snapshot: array<string, mixed>,
     *   analysis: array{
     *     summary: string,
     *     risk_level: string,
     *     anomalies: array<int, string>,
     *     recommendations: array<int, string>,
     *     explanation: string
     *   },
     *   raw_text: string,
     *   model: string
     * }
     */
    public function analyzeWallet(Wallet $wallet): array
    {
        $snapshot = $this->buildWalletSnapshot($wallet);
        $payload = $this->requestAnalysis($snapshot);
        $rawText = $this->extractOutputText($payload);
        $analysis = $this->normalizeAnalysis($this->decodeAnalysisJson($rawText), $snapshot);

        return [
            'snapshot' => $snapshot,
            'analysis' => $analysis,
            'raw_text' => $rawText,
            'model' => $this->getConfiguredModel(),
        ];
    }

    /**
     * @return array{
     *   snapshot: array<string, mixed>,
     *   analysis: array{
     *     summary: string,
     *     risk_level: string,
     *     anomalies: array<int, string>,
     *     recommendations: array<int, string>,
     *     explanation: string
     *   },
     *   raw_text: string,
     *   model: string,
     *   source: string,
     *   explanation_source: string,
     *   openai_available: bool
     * }
     */
    public function analyzeWalletBehaviorally(Wallet $wallet): array
    {
        $snapshot = $this->buildWalletSnapshot($wallet);
        $analysis = $this->buildLocalFallbackAnalysis($snapshot);
        $rawText = json_encode($analysis, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
        $model = self::LOCAL_FALLBACK_MODEL;
        $explanationSource = 'local';
        $openAiAvailable = false;

        try {
            $payload = $this->requestAnalysis($snapshot);
            $remoteText = $this->extractOutputText($payload);
            $remoteAnalysis = $this->normalizeAnalysis($this->decodeAnalysisJson($remoteText), $snapshot);

            // OpenAI can enrich the wording, but the local engine remains the system of record.
            if (trim($remoteAnalysis['summary']) !== '') {
                $analysis['summary'] = $remoteAnalysis['summary'];
            }
            if ($remoteAnalysis['anomalies'] !== []) {
                $analysis['anomalies'] = $remoteAnalysis['anomalies'];
            }
            if ($remoteAnalysis['recommendations'] !== []) {
                $analysis['recommendations'] = $remoteAnalysis['recommendations'];
            }
            if (trim($remoteAnalysis['explanation']) !== '') {
                $analysis['explanation'] = $remoteAnalysis['explanation'];
            }

            $rawText = $remoteText;
            $model = $this->getConfiguredModel();
            $explanationSource = 'openai';
            $openAiAvailable = true;
        } catch (\Throwable $exception) {
            $this->logger->info('OpenAI indisponible pour l enrichissement de l analyse wallet. Analyse locale conservee.', [
                'wallet_id' => $wallet->getIdWallet(),
                'reason' => $exception->getMessage(),
            ]);
        }

        return [
            'snapshot' => $snapshot,
            'analysis' => $analysis,
            'raw_text' => $rawText,
            'model' => $model,
            'source' => 'local_engine',
            'explanation_source' => $explanationSource,
            'openai_available' => $openAiAvailable,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function buildWalletSnapshot(Wallet $wallet): array
    {
        $analytics = $this->walletAnalyticsService->buildAnalytics($wallet);
        $anomalyReport = $this->anomalyDetectionService->detectAnomalies($wallet, $analytics);
        $riskAnalysis = $this->riskScoringService->scoreWallet($wallet, $analytics, $anomalyReport);
        $classification = $this->walletClassificationService->classifyWallet($wallet, $riskAnalysis, $anomalyReport, $analytics);
        $predictions = $this->predictionService->predictWallet($wallet, $analytics, $anomalyReport, $riskAnalysis);
        $metrics = is_array($analytics['metrics'] ?? null) ? $analytics['metrics'] : [];
        $recentActivity = is_array($analytics['recent_activity'] ?? null) ? $analytics['recent_activity'] : [];
        $anomalies = is_array($anomalyReport['anomalies_detected'] ?? null) ? $anomalyReport['anomalies_detected'] : [];
        $recentIndicator = is_array($analytics['recent_activity_indicator'] ?? null) ? $analytics['recent_activity_indicator'] : [];

        return [
            'wallet_id' => $wallet->getIdWallet(),
            'client_id' => $wallet->getIdUser(),
            'owner' => $wallet->getNomProprietaire(),
            'email' => $wallet->getEmail(),
            'currency' => $wallet->getDevise(),
            'balance' => round((float) $wallet->getSolde(), 2),
            'status' => $wallet->getStatut(),
            'is_active' => (bool) $wallet->getEstActif(),
            'is_blocked' => (bool) $wallet->getEstBloque(),
            'created_at' => $wallet->getDateCreation()?->format('Y-m-d H:i:s'),
            'failed_attempts' => (int) ($wallet->getTentativesEchouees() ?? 0),
            'total_transactions' => (int) ($metrics['total_transactions'] ?? 0),
            'transactions_last_24h' => (int) ($metrics['transactions_last_24h'] ?? 0),
            'transactions_last_7d' => (int) ($metrics['transactions_last_7d'] ?? 0),
            'recent_transactions_30d' => (int) ($metrics['transactions_last_30d'] ?? 0),
            'night_transaction_count' => (int) ($metrics['night_transaction_count'] ?? 0),
            'rapid_transaction_count' => (int) ($metrics['rapid_transaction_count'] ?? 0),
            'total_cheques' => (int) ($metrics['total_cheques'] ?? 0),
            'refused_cheques' => (int) ($metrics['refused_cheques'] ?? 0),
            'cheque_rejection_rate' => (float) ($metrics['cheque_rejection_rate'] ?? 0.0),
            'activity_frequency' => (string) ($metrics['recent_activity_label'] ?? 'inconnue'),
            'recent_activity_indicator' => $recentIndicator,
            'dominant_transaction_type' => $metrics['dominant_transaction_type'] ?? null,
            'average_transaction_amount' => (float) ($metrics['average_transaction_amount'] ?? 0.0),
            'max_transaction_amount' => (float) ($metrics['max_transaction_amount'] ?? 0.0),
            'min_transaction_amount' => (float) ($metrics['min_transaction_amount'] ?? 0.0),
            'usual_activity_hour' => $metrics['usual_activity_hour'] ?? null,
            'balance_instability_index' => (float) ($metrics['balance_instability_index'] ?? 0.0),
            'withdrawal_to_deposit_ratio' => (float) ($metrics['withdrawal_to_deposit_amount_ratio'] ?? 0.0),
            'latest_transactions' => array_slice($recentActivity, 0, 6),
            'analytics_metrics' => $metrics,
            'anomalies_detected' => $anomalies,
            'risk_scoring' => [
                'score' => (int) ($riskAnalysis['score'] ?? 0),
                'level' => (string) ($riskAnalysis['level'] ?? 'faible'),
                'factors' => $riskAnalysis['factors'] ?? [],
                'explanation' => (string) ($riskAnalysis['explanation'] ?? ''),
            ],
            'classification' => $classification,
            'predictions' => $predictions,
        ];
    }

    /**
     * @param array<string, mixed> $snapshot
     * @return array<string, mixed>
     */
    private function requestAnalysis(array $snapshot): array
    {
        if (trim((string) $this->openAiApiKey) === '') {
            throw new \RuntimeException('Configuration OpenAI invalide : la cle API est absente. Configurez OPENAI_API_KEY dans votre environnement.');
        }

        $instructions = 'Tu es un assistant de supervision bancaire pour un back-office admin Symfony. '
            . 'Analyse un wallet client a partir de donnees structurees. '
            . 'Retourne uniquement un objet JSON valide avec les cles: '
            . 'summary, risk_level, anomalies, recommendations, explanation. '
            . 'risk_level doit etre une des valeurs exactes suivantes: faible, moyen, eleve, critique. '
            . 'anomalies et recommendations doivent etre des tableaux de phrases courtes en francais. '
            . 'Le ton doit etre professionnel, bancaire, prudent et exploitable par un administrateur.';

        $input = json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($input) || $input === '') {
            throw new \RuntimeException('La serialisation du snapshot wallet a echoue avant l appel OpenAI.');
        }

        try {
            $response = $this->httpClient->request('POST', self::API_URL, [
                'headers' => [
                    'Authorization' => 'Bearer ' . (string) $this->openAiApiKey,
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'model' => $this->getConfiguredModel(),
                    'instructions' => $instructions,
                    'input' => [[
                        'role' => 'user',
                        'content' => [[
                            'type' => 'input_text',
                            'text' => "Snapshot wallet a analyser:\n" . $input,
                        ]],
                    ]],
                    'text' => [
                        'format' => [
                            'type' => 'json_schema',
                            'name' => 'wallet_analysis',
                            'schema' => $this->getAnalysisJsonSchema(),
                            'strict' => true,
                        ],
                    ],
                    'max_output_tokens' => 900,
                ],
                'timeout' => self::REQUEST_TIMEOUT_SECONDS,
            ]);

            $statusCode = $response->getStatusCode();
            $rawBody = $response->getContent(false);

            /** @var array<string, mixed>|null $payload */
            $payload = json_decode($rawBody, true);
            if (!is_array($payload)) {
                $payload = null;
            }

            if ($statusCode >= 400) {
                $apiMessage = is_string($payload['error']['message'] ?? null)
                    ? $payload['error']['message']
                    : 'Erreur OpenAI inconnue.';
                $apiCode = is_string($payload['error']['code'] ?? null)
                    ? $payload['error']['code']
                    : null;
                $apiType = is_string($payload['error']['type'] ?? null)
                    ? $payload['error']['type']
                    : null;

                throw $this->buildHttpException($statusCode, $apiMessage, $apiCode, $apiType);
            }

            if ($payload === null) {
                throw new \RuntimeException('OpenAI a retourne une reponse HTTP invalide ou non JSON.');
            }

            return $payload;
        } catch (TransportExceptionInterface $exception) {
            $message = mb_strtolower($exception->getMessage());
            $userMessage = str_contains($message, 'timed out')
                ? 'Timeout OpenAI : le service n a pas repondu dans le delai imparti.'
                : 'Erreur reseau OpenAI : impossible de joindre le service externe.';

            $this->logger->error('Erreur reseau OpenAI pendant l analyse IA du wallet.', [
                'message' => $exception->getMessage(),
                'model' => $this->getConfiguredModel(),
                'url' => self::API_URL,
                'wallet_id' => $snapshot['wallet_id'] ?? null,
                'exception' => $exception,
            ]);

            throw new \RuntimeException($userMessage, previous: $exception);
        } catch (\Throwable $exception) {
            $this->logger->error('Erreur pendant l analyse IA du wallet.', [
                'message' => $exception->getMessage(),
                'model' => $this->getConfiguredModel(),
                'url' => self::API_URL,
                'wallet_id' => $snapshot['wallet_id'] ?? null,
                'exception' => $exception,
            ]);

            throw $exception instanceof \RuntimeException
                ? $exception
                : new \RuntimeException('L analyse IA du wallet a echoue pour une raison inattendue.', previous: $exception);
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function extractOutputText(array $payload): string
    {
        $output = $payload['output'] ?? null;
        if (!is_array($output)) {
            throw new \RuntimeException('La reponse OpenAI ne contient aucun bloc de sortie exploitable.');
        }

        $chunks = [];

        foreach ($output as $item) {
            if (!is_array($item)) {
                continue;
            }

            $content = $item['content'] ?? null;
            if (!is_array($content)) {
                continue;
            }

            foreach ($content as $contentItem) {
                if (!is_array($contentItem)) {
                    continue;
                }

                if (($contentItem['type'] ?? null) === 'output_text' && is_string($contentItem['text'] ?? null)) {
                    $chunks[] = trim($contentItem['text']);
                }
            }
        }

        $text = trim(implode("\n", array_filter($chunks)));

        if ($text === '') {
            throw new \RuntimeException('OpenAI a retourne une reponse vide : aucun texte d analyse exploitable.');
        }

        return $text;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeAnalysisJson(string $rawText): array
    {
        $decoded = json_decode($rawText, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        if (preg_match('/\{.*\}/s', $rawText, $matches) !== 1) {
            throw new \RuntimeException('La sortie OpenAI ne contient pas de JSON exploitable. Verifiez le modele et le format de reponse demande.');
        }

        $decoded = json_decode($matches[0], true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('La sortie OpenAI contient un JSON invalide.');
        }

        return $decoded;
    }

    /**
     * @param array<string, mixed> $decoded
     * @param array<string, mixed> $snapshot
     * @return array{
     *   summary:string,
     *   risk_level:string,
     *   anomalies:array<int, string>,
     *   recommendations:array<int, string>,
     *   explanation:string
     * }
     */
    private function normalizeAnalysis(array $decoded, array $snapshot): array
    {
        $riskLevel = mb_strtolower(trim((string) ($decoded['risk_level'] ?? 'moyen')));
        if (!in_array($riskLevel, ['faible', 'moyen', 'eleve', 'critique'], true)) {
            $riskLevel = $this->inferFallbackRiskLevel($snapshot);
        }

        return [
            'summary' => trim((string) ($decoded['summary'] ?? 'Analyse non disponible.')),
            'risk_level' => $riskLevel,
            'anomalies' => $this->normalizeTextList($decoded['anomalies'] ?? []),
            'recommendations' => $this->normalizeTextList($decoded['recommendations'] ?? []),
            'explanation' => trim((string) ($decoded['explanation'] ?? 'Aucune explication fournie.')),
        ];
    }

    /**
     * @param mixed $value
     * @return array<int, string>
     */
    private function normalizeTextList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $items = [];
        foreach ($value as $entry) {
            $text = trim((string) $entry);
            if ($text !== '') {
                $items[] = $text;
            }
        }

        return array_values(array_unique($items));
    }

    /**
     * @param array<string, mixed> $snapshot
     */
    private function inferFallbackRiskLevel(array $snapshot): string
    {
        $riskLevel = mb_strtolower((string) ($snapshot['risk_scoring']['level'] ?? ''));
        if (in_array($riskLevel, ['faible', 'moyen', 'eleve', 'critique'], true)) {
            return $riskLevel;
        }

        $score = 0;
        if (($snapshot['is_blocked'] ?? false) === true) {
            $score += 2;
        }
        if ((int) ($snapshot['refused_cheques'] ?? 0) >= 2) {
            $score += 1;
        }
        if ((int) ($snapshot['transactions_last_24h'] ?? 0) >= 4) {
            $score += 1;
        }

        return match (true) {
            $score >= 3 => 'eleve',
            $score >= 1 => 'moyen',
            default => 'faible',
        };
    }

    /**
     * @param array<string, mixed> $snapshot
     * @return array{
     *   summary:string,
     *   risk_level:string,
     *   anomalies:array<int, string>,
     *   recommendations:array<int, string>,
     *   explanation:string
     * }
     */
    private function buildLocalFallbackAnalysis(array $snapshot): array
    {
        $riskLevel = $this->inferFallbackRiskLevel($snapshot);
        $anomalies = [];
        $recommendations = [];

        $balance = (float) ($snapshot['balance'] ?? 0.0);
        $recentTransactions = (int) ($snapshot['recent_transactions_30d'] ?? 0);
        $refusedCheques = (int) ($snapshot['refused_cheques'] ?? 0);
        $failedAttempts = (int) ($snapshot['failed_attempts'] ?? 0);
        $isBlocked = (bool) ($snapshot['is_blocked'] ?? false);
        $isActive = (bool) ($snapshot['is_active'] ?? false);
        $status = (string) ($snapshot['status'] ?? 'inconnu');
        $frequency = (string) ($snapshot['activity_frequency'] ?? 'inconnue');
        $currency = (string) ($snapshot['currency'] ?? '');
        $dominantType = (string) ($snapshot['dominant_transaction_type'] ?? 'non defini');
        $riskScore = (int) ($snapshot['risk_scoring']['score'] ?? 0);
        $riskFactors = is_array($snapshot['risk_scoring']['factors'] ?? null) ? $snapshot['risk_scoring']['factors'] : [];
        $structuredAnomalies = is_array($snapshot['anomalies_detected'] ?? null) ? $snapshot['anomalies_detected'] : [];
        $predictions = is_array($snapshot['predictions'] ?? null) ? $snapshot['predictions'] : [];

        if ($isBlocked) {
            $anomalies[] = 'Le wallet est actuellement bloque, ce qui signale un dossier sensible a surveiller.';
            $recommendations[] = 'Verifier l origine du blocage et confirmer si une revue manuelle du compte est encore necessaire.';
        }

        if (!$isActive || mb_strtolower($status) !== 'actif') {
            $anomalies[] = 'Le statut du wallet n est pas pleinement actif.';
            $recommendations[] = 'Verifier si le statut du wallet est coherent avec la situation du client et les dernieres operations.';
        }

        if ($refusedCheques >= 2) {
            $anomalies[] = 'Le nombre de cheques refuses est eleve pour ce wallet.';
            $recommendations[] = 'Examiner les motifs des refus de cheques et renforcer le controle sur les moyens de paiement lies.';
        } elseif ($refusedCheques === 1) {
            $anomalies[] = 'Un cheque refuse a ete detecte sur ce wallet.';
        }

        if ($failedAttempts >= 3) {
            $anomalies[] = 'Plusieurs tentatives d acces ou validations echouees ont ete relevees.';
            $recommendations[] = 'Controler les tentatives echouees et verifier s il s agit d une erreur client ou d un comportement suspect.';
        }

        foreach ($structuredAnomalies as $anomaly) {
            if (!is_array($anomaly)) {
                continue;
            }

            $title = trim((string) ($anomaly['title'] ?? ''));
            $description = trim((string) ($anomaly['details'] ?? $anomaly['description'] ?? ''));
            if ($title !== '' && $description !== '') {
                $anomalies[] = $title . ' : ' . $description;
            }
        }

        if ($recentTransactions >= 20) {
            $anomalies[] = 'Le volume de transactions recentes est tres soutenu.';
            $recommendations[] = 'Comparer cette intensite d activite avec l historique habituel du client pour confirmer qu elle est legitime.';
        } elseif ($recentTransactions === 0) {
            $anomalies[] = 'Aucune transaction recente n a ete observee sur les 30 derniers jours.';
            $recommendations[] = 'Verifier si l inactivite du wallet est attendue ou si un suivi commercial ou de securite est utile.';
        }

        if ($balance < 0) {
            $anomalies[] = 'Le solde est negatif, ce qui peut indiquer une situation financiere fragile ou un decouvert a encadrer.';
            $recommendations[] = 'Verifier les limites autorisees et contacter le client si la situation se prolonge.';
        } elseif ($balance === 0.0 && $recentTransactions > 0) {
            $anomalies[] = 'Le wallet presente un solde nul malgre une activite recente.';
        }

        if ($frequency === 'tres soutenue') {
            $recommendations[] = 'Analyser les dernieres transactions pour confirmer la coherence des montants, types et descriptions.';
        }

        if (($predictions['admin_recommendation'] ?? null) === 'bloquer conseille') {
            $recommendations[] = 'Une mise en blocage precautionnelle peut etre envisagee si les verifications documentaires ne permettent pas de justifier les flux.';
        } elseif (($predictions['admin_recommendation'] ?? null) === 'risque eleve') {
            $recommendations[] = 'Mettre le wallet sous surveillance renforcee pendant les 7 prochains jours.';
        }

        if ($anomalies === []) {
            $anomalies[] = 'Aucune anomalie majeure n a ete detectee par l analyse locale sur les indicateurs disponibles.';
        }

        if ($recommendations === []) {
            $recommendations[] = 'Maintenir une surveillance standard et suivre l evolution du wallet lors des prochains controles.';
        }

        $summary = sprintf(
            'Le wallet affiche un solde de %.2f %s, un statut %s et une activite %s avec %d transaction(s) recente(s) sur 30 jours. Le type dominant est %s et le score de risque atteint %d/100.',
            $balance,
            $currency,
            $status,
            $frequency,
            $recentTransactions,
            $dominantType,
            $riskScore
        );

        if ($refusedCheques > 0) {
            $summary .= sprintf(' %d cheque(s) refuse(s) renforcent le besoin de vigilance.', $refusedCheques);
        }

        $topFactors = array_slice(array_map(static function (array $factor): string {
            return sprintf('%s (+%d)', (string) ($factor['label'] ?? 'Facteur'), (int) ($factor['points'] ?? 0));
        }, $riskFactors), 0, 3);

        $explanation = match ($riskLevel) {
            'critique' => 'Le niveau de risque est critique car plusieurs signaux convergent vers une exposition forte du wallet. Une action administrative rapide est recommandee.',
            'eleve' => 'Le niveau de risque est estime eleve car plusieurs signaux de vigilance sont combines, comme un blocage, des refus ou une activite inhabituelle. Une revue admin rapide est recommandee.',
            'moyen' => 'Le niveau de risque est estime moyen car certains indicateurs meritent une verification manuelle, sans montrer necessairement une fraude certaine. Une surveillance renforcee est conseillee.',
            default => 'Le niveau de risque est estime faible car les indicateurs observes restent globalement coherents et ne montrent pas de signal critique immediat.',
        };

        if ($topFactors !== []) {
            $explanation .= ' Facteurs principaux : ' . implode(', ', $topFactors) . '.';
        }

        return [
            'summary' => $summary,
            'risk_level' => $riskLevel,
            'anomalies' => array_values(array_unique($anomalies)),
            'recommendations' => array_values(array_unique($recommendations)),
            'explanation' => $explanation,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function getAnalysisJsonSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['summary', 'risk_level', 'anomalies', 'recommendations', 'explanation'],
            'properties' => [
                'summary' => [
                    'type' => 'string',
                ],
                'risk_level' => [
                    'type' => 'string',
                    'enum' => ['faible', 'moyen', 'eleve', 'critique'],
                ],
                'anomalies' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                ],
                'recommendations' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                ],
                'explanation' => [
                    'type' => 'string',
                ],
            ],
        ];
    }

    private function buildHttpException(int $statusCode, string $apiMessage, ?string $apiCode, ?string $apiType): \RuntimeException
    {
        $normalizedMessage = mb_strtolower($apiMessage);
        $normalizedCode = mb_strtolower((string) $apiCode);
        $normalizedType = mb_strtolower((string) $apiType);

        $message = match (true) {
            $statusCode === 401 => 'Cle API OpenAI invalide ou non autorisee. Verifiez OPENAI_API_KEY.',
            $statusCode === 403 => 'Acces OpenAI refuse. Verifiez les permissions du projet API ou les restrictions configurees.',
            $statusCode === 404 || str_contains($normalizedCode, 'model') || str_contains($normalizedMessage, 'model') || str_contains($normalizedType, 'model') => 'Modele OpenAI indisponible ou non autorise. Verifiez OPENAI_MODEL.',
            $statusCode === 408 => 'Timeout OpenAI : la requete a depasse le delai autorise.',
            $statusCode === 429 && str_contains($normalizedMessage, 'quota') => 'Quota OpenAI epuise. Verifiez la facturation et les credits de votre compte API.',
            $statusCode === 429 => 'Limite OpenAI atteinte. Reessayez dans quelques instants.',
            $statusCode >= 500 => 'Service OpenAI temporairement indisponible. Reessayez plus tard.',
            default => sprintf('Erreur HTTP OpenAI (%d) : %s', $statusCode, $apiMessage),
        };

        return new \RuntimeException($message);
    }

    private function getConfiguredModel(): string
    {
        $model = trim((string) $this->openAiModel);

        return $model !== '' ? $model : self::LOCAL_FALLBACK_MODEL;
    }
}
