<?php

namespace App\Service;

use App\Entity\Wallet\Wallet;

class PredictionService
{
    public function __construct(
        private readonly WalletAnalyticsService $walletAnalyticsService,
        private readonly AnomalyDetectionService $anomalyDetectionService,
        private readonly RiskScoringService $riskScoringService,
    ) {
    }

    /**
     * @param array<string, mixed>|null $analytics
     * @param array<string, mixed>|null $anomalyReport
     * @param array<string, mixed>|null $riskAnalysis
     * @return array<string, mixed>
     */
    public function predictWallet(Wallet $wallet, ?array $analytics = null, ?array $anomalyReport = null, ?array $riskAnalysis = null): array
    {
        $analytics ??= $this->walletAnalyticsService->buildAnalytics($wallet);
        $anomalyReport ??= $this->anomalyDetectionService->detectAnomalies($wallet, $analytics);
        $riskAnalysis ??= $this->riskScoringService->scoreWallet($wallet, $analytics, $anomalyReport);

        $metrics = is_array($analytics['metrics'] ?? null) ? $analytics['metrics'] : [];
        $recentActivity = is_array($analytics['recent_activity'] ?? null) ? $analytics['recent_activity'] : [];
        $anomalies = is_array($anomalyReport['anomalies_detected'] ?? null) ? $anomalyReport['anomalies_detected'] : [];
        $score = (int) ($riskAnalysis['score'] ?? 0);

        $highSeverityCount = 0;
        foreach ($anomalies as $anomaly) {
            if (in_array((string) ($anomaly['severity'] ?? ''), ['high', 'critical'], true)) {
                $highSeverityCount++;
            }
        }

        $blockProbability = 10;
        $blockProbability += (int) round($score * 0.55);
        $blockProbability += $highSeverityCount * 8;

        if ((bool) ($metrics['is_blocked'] ?? false)) {
            $blockProbability += 15;
        }

        if ((float) ($metrics['withdrawal_to_deposit_amount_ratio'] ?? 0.0) >= 2.5) {
            $blockProbability += 8;
        }

        $balanceTrend = $this->computeBalanceTrend($recentActivity, (float) ($metrics['current_balance'] ?? 0.0));

        $futureRisk7d = match (true) {
            $score >= 80 || $blockProbability >= 85 => 'critique',
            $score >= 55 || $highSeverityCount >= 2 || $balanceTrend['direction'] === 'forte_baisse' => 'eleve',
            $score >= 30 || $balanceTrend['direction'] === 'baisse_legere' => 'moyen',
            default => 'faible',
        };

        $adminRecommendation = match (true) {
            $futureRisk7d === 'critique' || $blockProbability >= 90 => 'bloquer conseille',
            $futureRisk7d === 'eleve' => 'risque eleve',
            $futureRisk7d === 'moyen' || $score >= 30 => 'surveiller',
            default => 'normal',
        };

        return [
            'block_probability' => max(5, min(95, $blockProbability)),
            'balance_trend' => $balanceTrend,
            'future_risk_7d' => $futureRisk7d,
            'admin_recommendation' => $adminRecommendation,
            'rationale' => $this->buildRationale($metrics, $score, $highSeverityCount, $balanceTrend, $adminRecommendation),
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $recentActivity
     * @return array<string, mixed>
     */
    private function computeBalanceTrend(array $recentActivity, float $balance): array
    {
        if ($recentActivity === []) {
            return [
                'direction' => 'stable',
                'label' => 'stable',
                'net_flow' => 0.0,
                'confidence' => 'faible',
            ];
        }

        $window = array_slice($recentActivity, 0, 7);
        $netFlow = 0.0;
        foreach ($window as $row) {
            $netFlow += (float) ($row['signed_amount'] ?? 0.0);
        }

        $sensitivity = max(75.0, abs($balance) * 0.12);

        return match (true) {
            $netFlow <= -$sensitivity => [
                'direction' => 'forte_baisse',
                'label' => 'baisse',
                'net_flow' => round($netFlow, 2),
                'confidence' => 'elevee',
            ],
            $netFlow < 0 => [
                'direction' => 'baisse_legere',
                'label' => 'leger recul',
                'net_flow' => round($netFlow, 2),
                'confidence' => 'moyenne',
            ],
            $netFlow >= $sensitivity => [
                'direction' => 'hausse',
                'label' => 'hausse',
                'net_flow' => round($netFlow, 2),
                'confidence' => 'elevee',
            ],
            default => [
                'direction' => 'stable',
                'label' => 'stable',
                'net_flow' => round($netFlow, 2),
                'confidence' => 'moyenne',
            ],
        };
    }

    /**
     * @param array<string, mixed> $metrics
     * @return array<int, string>
     */
    private function buildRationale(array $metrics, int $score, int $highSeverityCount, array $balanceTrend, string $adminRecommendation): array
    {
        $rationale = [
            sprintf('Score de risque calcule localement : %d/100.', $score),
            sprintf('Anomalies severes detectees : %d.', $highSeverityCount),
        ];

        if ((float) ($metrics['recent_vs_historical_activity_index'] ?? 1.0) >= 1.8) {
            $rationale[] = 'L activite recente depasse nettement la baseline historique.';
        }

        if ((float) ($metrics['withdrawal_to_deposit_amount_ratio'] ?? 0.0) >= 1.8) {
            $rationale[] = 'Les sorties dominent les entrees, ce qui fragilise la trajectoire du wallet.';
        }

        $rationale[] = sprintf('Tendance du solde estimee : %s.', (string) ($balanceTrend['label'] ?? 'stable'));
        $rationale[] = sprintf('Recommendation admin retenue : %s.', $adminRecommendation);

        return $rationale;
    }
}
