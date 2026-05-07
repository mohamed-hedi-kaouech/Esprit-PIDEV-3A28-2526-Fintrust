<?php

namespace App\Service;

use App\Entity\Wallet\Wallet;

class RiskScoringService
{
    public function __construct(
        private readonly WalletAnalyticsService $walletAnalyticsService,
        private readonly AnomalyDetectionService $anomalyDetectionService,
    ) {
    }

    /**
     * @param array<string, mixed>|null $analytics
     * @param array<string, mixed>|null $anomalyReport
     * @return array<string, mixed>
     */
    public function scoreWallet(Wallet $wallet, ?array $analytics = null, ?array $anomalyReport = null): array
    {
        $analytics ??= $this->walletAnalyticsService->buildAnalytics($wallet);
        $anomalyReport ??= $this->anomalyDetectionService->detectAnomalies($wallet, $analytics);

        $metrics = is_array($analytics['metrics'] ?? null) ? $analytics['metrics'] : [];
        $anomalies = is_array($anomalyReport['anomalies_detected'] ?? null) ? $anomalyReport['anomalies_detected'] : [];

        $score = 0;
        $factors = [];

        foreach ($anomalies as $anomaly) {
            if (!is_array($anomaly)) {
                continue;
            }

            $code = (string) ($anomaly['code'] ?? '');
            $points = match ($code) {
                'rapid_transactions' => 18,
                'abnormal_night_activity' => 10,
                'unusual_amount' => 13,
                'sudden_activity_change' => 12,
                'withdrawal_imbalance' => 14,
                'multiple_refused_cheques' => 12,
                'balance_instability' => 11,
                default => 0,
            };

            if ($points > 0) {
                $score += $points;
                $factors[] = [
                    'label' => (string) ($anomaly['title'] ?? $code),
                    'points' => $points,
                    'reason' => (string) ($anomaly['details'] ?? 'Signal de risque detecte.'),
                ];
            }
        }

        $rapidCount = (int) ($metrics['rapid_transaction_count'] ?? 0);
        if ($rapidCount >= 5) {
            $score += 8;
            $factors[] = [
                'label' => 'Concentration d operations',
                'points' => 8,
                'reason' => sprintf('%d transactions sont impliquees dans des sequences rapides.', $rapidCount),
            ];
        }

        $nightCount = (int) ($metrics['night_transaction_count'] ?? 0);
        if ($nightCount >= 3) {
            $score += 6;
            $factors[] = [
                'label' => 'Activite nocturne recurrente',
                'points' => 6,
                'reason' => sprintf('%d transaction(s) ont ete detectees sur plage nocturne.', $nightCount),
            ];
        }

        $activityIndex = (float) ($metrics['recent_vs_historical_activity_index'] ?? 1.0);
        if ($activityIndex >= 2.5) {
            $score += 12;
            $factors[] = [
                'label' => 'Changement brutal de comportement',
                'points' => 12,
                'reason' => sprintf('L activite recente est %.2fx au-dessus de la base historique.', $activityIndex),
            ];
        } elseif ($activityIndex >= 1.5) {
            $score += 6;
            $factors[] = [
                'label' => 'Acceleration inhabituelle',
                'points' => 6,
                'reason' => sprintf('L activite recente augmente de facon notable (indice %.2f).', $activityIndex),
            ];
        }

        $volatility = (float) ($metrics['balance_instability_index'] ?? 0.0);
        if ($volatility >= 1.5) {
            $score += 10;
            $factors[] = [
                'label' => 'Volatilite du solde',
                'points' => 10,
                'reason' => sprintf('L indice de volatilite du solde est eleve (%.2f).', $volatility),
            ];
        }

        $withdrawalRatio = (float) ($metrics['withdrawal_to_deposit_amount_ratio'] ?? 0.0);
        if ($withdrawalRatio >= 3.0) {
            $score += 10;
            $factors[] = [
                'label' => 'Retraits tres dominants',
                'points' => 10,
                'reason' => sprintf('Le ratio retraits/depots atteint %.2f.', $withdrawalRatio),
            ];
        } elseif ($withdrawalRatio >= 1.8) {
            $score += 5;
            $factors[] = [
                'label' => 'Pression sur les sorties',
                'points' => 5,
                'reason' => sprintf('Le ratio retraits/depots depasse la zone de confort (%.2f).', $withdrawalRatio),
            ];
        }

        $refusedCheques = (int) ($metrics['refused_cheques'] ?? 0);
        if ($refusedCheques >= 3) {
            $score += 8;
            $factors[] = [
                'label' => 'Cheques refuses repetes',
                'points' => 8,
                'reason' => sprintf('%d cheque(s) refuse(s) renforcent le risque operationnel.', $refusedCheques),
            ];
        }

        if ((bool) ($metrics['is_blocked'] ?? false)) {
            $score += 16;
            $factors[] = [
                'label' => 'Wallet deja bloque',
                'points' => 16,
                'reason' => 'Le wallet est deja bloque administrativement, ce qui augmente le score de risque global.',
            ];
        }

        if ((int) ($wallet->getTentativesEchouees() ?? 0) >= 3) {
            $score += 6;
            $factors[] = [
                'label' => 'Tentatives echouees multiples',
                'points' => 6,
                'reason' => sprintf('%d tentative(s) echouee(s) ont ete enregistree(s).', (int) ($wallet->getTentativesEchouees() ?? 0)),
            ];
        }

        $score = min(100, $score);
        $level = $this->computeRiskLevel($score);

        usort($factors, static fn (array $left, array $right): int => ((int) $right['points']) <=> ((int) $left['points']));

        return [
            'score' => $score,
            'level' => $level,
            'factors' => $factors,
            'explanation' => $this->buildExplanation($score, $level, $factors),
        ];
    }

    private function computeRiskLevel(int $score): string
    {
        return match (true) {
            $score >= 80 => 'critique',
            $score >= 55 => 'eleve',
            $score >= 30 => 'moyen',
            default => 'faible',
        };
    }

    /**
     * @param array<int, array<string, mixed>> $factors
     */
    private function buildExplanation(int $score, string $level, array $factors): string
    {
        if ($factors === []) {
            return sprintf('Le score de risque est de %d/100 (%s). Aucun facteur majeur ne ressort de l historique du wallet.', $score, $level);
        }

        $topFactors = array_slice(array_map(
            static fn (array $factor): string => sprintf('%s (+%d)', (string) ($factor['label'] ?? 'Facteur'), (int) $factor['points']),
            $factors
        ), 0, 3);

        return sprintf(
            'Le wallet obtient un score de %d/100 (%s). Les principaux facteurs sont : %s.',
            $score,
            $level,
            implode(', ', $topFactors)
        );
    }
}
