<?php

namespace App\Service;

use App\Entity\Wallet\Wallet;

class WalletClassificationService
{
    public function __construct(
        private readonly RiskScoringService $riskScoringService,
        private readonly AnomalyDetectionService $anomalyDetectionService,
        private readonly WalletAnalyticsService $walletAnalyticsService,
    ) {
    }

    /**
     * @param array<string, mixed>|null $riskAnalysis
     * @param array<string, mixed>|null $anomalyReport
     * @param array<string, mixed>|null $analytics
     * @return array<string, mixed>
     */
    public function classifyWallet(Wallet $wallet, ?array $riskAnalysis = null, ?array $anomalyReport = null, ?array $analytics = null): array
    {
        $analytics ??= $this->walletAnalyticsService->buildAnalytics($wallet);
        $anomalyReport ??= $this->anomalyDetectionService->detectAnomalies($wallet, $analytics);
        $riskAnalysis ??= $this->riskScoringService->scoreWallet($wallet, $analytics, $anomalyReport);

        $score = (int) ($riskAnalysis['score'] ?? 0);
        $anomalies = is_array($anomalyReport['anomalies_detected'] ?? null) ? $anomalyReport['anomalies_detected'] : [];
        $highOrCritical = 0;
        foreach ($anomalies as $anomaly) {
            $severity = (string) ($anomaly['severity'] ?? '');
            if (in_array($severity, ['high', 'critical'], true)) {
                $highOrCritical++;
            }
        }

        $classNumber = match (true) {
            $score >= 85 || $highOrCritical >= 3 => 5,
            $score >= 65 || $highOrCritical >= 2 => 4,
            $score >= 45 || count($anomalies) >= 3 => 3,
            $score >= 20 || count($anomalies) >= 1 => 2,
            default => 1,
        };

        $classLabel = match ($classNumber) {
            1 => 'Sain',
            2 => 'Sous surveillance',
            3 => 'Sensible',
            4 => 'Risque',
            default => 'Critique',
        };

        $recommendation = match ($classNumber) {
            1 => 'Maintenir une surveillance standard et revue periodique.',
            2 => 'Surveiller les flux recents et confirmer la coherence des operations.',
            3 => 'Lancer une revue metier ciblee et verifier les justificatifs des flux sensibles.',
            4 => 'Mettre sous surveillance renforcee avec controle manuel a court terme.',
            default => 'Envisager un blocage precautionnel et une revue conformite immediate.',
        };

        return [
            'class_number' => $classNumber,
            'class_label' => $classLabel,
            'score' => $score,
            'recommendation' => $recommendation,
        ];
    }
}
