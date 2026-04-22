<?php

namespace App\Service\AML;

use App\Entity\Wallet\Wallet;

/**
 * Calcule le score AML (0–100) d'un wallet en combinant
 * le score de risque existant et des signaux spécifiques anti-blanchiment.
 */
class AMLScoringService
{
    /**
     * @param array $analytics   Résultat de WalletAnalyticsService::buildAnalytics()
     * @param array $riskAnalysis Résultat de RiskScoringService::scoreWallet()
     * @return array{score: int, level: string, factors: list<array{code:string,label:string,points:int,detail:string}>}
     */
    public function compute(Wallet $wallet, array $analytics, array $riskAnalysis): array
    {
        $metrics = is_array($analytics['metrics'] ?? null) ? $analytics['metrics'] : [];
        $score = 0;
        $factors = [];

        // ── 1. Base : score de risque général (40 % du score risque) ─────────
        $riskScore = (int) ($riskAnalysis['score'] ?? 0);
        $baseContrib = (int) round($riskScore * 0.40);
        if ($baseContrib > 0) {
            $score += $baseContrib;
            $factors[] = [
                'code'   => 'base_risk',
                'label'  => 'Score de risque général',
                'points' => $baseContrib,
                'detail' => sprintf('%d pts (40 %% du score risque %d/100)', $baseContrib, $riskScore),
            ];
        }

        // ── 2. Fractionnement / seuils réglementaires (structuring) ──────────
        $structuringPts = $this->detectStructuring($wallet);
        if ($structuringPts > 0) {
            $score += $structuringPts;
            $factors[] = [
                'code'   => 'structuring',
                'label'  => 'Fractionnement (structuring)',
                'points' => $structuringPts,
                'detail' => 'Transactions proches des seuils réglementaires (85–99 %).',
            ];
        }

        // ── 3. Hausse brutale d\'activité (volume spike) ──────────────────────
        $activityIndex = (float) ($metrics['recent_vs_historical_activity_index'] ?? 1.0);
        if ($activityIndex >= 3.0) {
            $pts = 12;
            $score += $pts;
            $factors[] = [
                'code'   => 'volume_spike',
                'label'  => 'Hausse brutale d\'activité',
                'points' => $pts,
                'detail' => sprintf('Indice d\'activité : ×%.1f vs historique.', $activityIndex),
            ];
        } elseif ($activityIndex >= 1.8) {
            $pts = 6;
            $score += $pts;
            $factors[] = [
                'code'   => 'volume_spike',
                'label'  => 'Hausse notable d\'activité',
                'points' => $pts,
                'detail' => sprintf('Indice d\'activité : ×%.1f vs historique.', $activityIndex),
            ];
        }

        // ── 4. Réactivation après inactivité prolongée ────────────────────────
        $dormantPts = $this->detectDormantReactivation($wallet, $activityIndex);
        if ($dormantPts > 0) {
            $score += $dormantPts;
            $factors[] = [
                'code'   => 'dormant_reactivation',
                'label'  => 'Réactivation après inactivité',
                'points' => $dormantPts,
                'detail' => 'Wallet dormant puis forte reprise d\'activité.',
            ];
        }

        // ── 5. Concentration nocturne ─────────────────────────────────────────
        $nightCount = (int) ($metrics['night_transaction_count'] ?? 0);
        $totalTx    = max(1, (int) ($metrics['total_transactions'] ?? 1));
        $nightRatio = $nightCount / $totalTx;
        if ($nightCount >= 3 && $nightRatio >= 0.30) {
            $pts = 9;
            $score += $pts;
            $factors[] = [
                'code'   => 'night_concentration',
                'label'  => 'Concentration nocturne anormale',
                'points' => $pts,
                'detail' => sprintf('%d tx nocturnes (%.0f %% du total).', $nightCount, $nightRatio * 100),
            ];
        } elseif ($nightCount >= 2) {
            $pts = 4;
            $score += $pts;
            $factors[] = [
                'code'   => 'night_concentration',
                'label'  => 'Activité nocturne détectée',
                'points' => $pts,
                'detail' => sprintf('%d transactions entre 22 h et 6 h.', $nightCount),
            ];
        }

        // ── 6. Pression de retraits (layering) ───────────────────────────────
        $wdRatio = (float) ($metrics['withdrawal_to_deposit_amount_ratio'] ?? 1.0);
        if ($wdRatio >= 3.0) {
            $pts = 11;
            $score += $pts;
            $factors[] = [
                'code'   => 'withdrawal_pressure',
                'label'  => 'Pression de retraits excessifs',
                'points' => $pts,
                'detail' => sprintf('Rapport retrait/dépôt : %.1f×', $wdRatio),
            ];
        } elseif ($wdRatio >= 1.8) {
            $pts = 6;
            $score += $pts;
            $factors[] = [
                'code'   => 'withdrawal_pressure',
                'label'  => 'Déséquilibre retraits/dépôts',
                'points' => $pts,
                'detail' => sprintf('Rapport retrait/dépôt : %.1f×', $wdRatio),
            ];
        }

        // ── 7. Signal de fraude chèque ────────────────────────────────────────
        $refusedCheques = (int) ($metrics['refused_cheques'] ?? 0);
        $rejectionRate  = (float) ($metrics['cheque_rejection_rate'] ?? 0.0);
        if ($refusedCheques >= 3 || $rejectionRate >= 0.50) {
            $pts = 9;
            $score += $pts;
            $factors[] = [
                'code'   => 'cheque_fraud',
                'label'  => 'Fraude chèque suspectée',
                'points' => $pts,
                'detail' => sprintf('%d chèques refusés (taux %.0f %%).', $refusedCheques, $rejectionRate * 100),
            ];
        } elseif ($refusedCheques >= 2) {
            $pts = 5;
            $score += $pts;
            $factors[] = [
                'code'   => 'cheque_fraud',
                'label'  => 'Chèques refusés multiples',
                'points' => $pts,
                'detail' => sprintf('%d chèques refusés détectés.', $refusedCheques),
            ];
        }

        // ── 8. Montants ronds suspects (smurfing) ─────────────────────────────
        $roundPts = $this->detectRoundAmounts($analytics);
        if ($roundPts > 0) {
            $score += $roundPts;
            $factors[] = [
                'code'   => 'round_amounts',
                'label'  => 'Montants ronds suspects',
                'points' => $roundPts,
                'detail' => 'Proportion anormale de montants multiples de 500.',
            ];
        }

        // ── 9. Tentatives d\'accès échouées ───────────────────────────────────
        $failed = (int) ($wallet->getTentativesEchouees() ?? 0);
        if ($failed >= 3) {
            $pts = 6;
            $score += $pts;
            $factors[] = [
                'code'   => 'failed_access',
                'label'  => 'Tentatives d\'accès échouées',
                'points' => $pts,
                'detail' => sprintf('%d tentatives échouées détectées.', $failed),
            ];
        }

        // ── 10. Wallet bloqué ────────────────────────────────────────────────
        if ($wallet->getEstBloque()) {
            $pts = 8;
            $score += $pts;
            $factors[] = [
                'code'   => 'blocked_wallet',
                'label'  => 'Wallet bloqué administrativement',
                'points' => $pts,
                'detail' => 'Le wallet est en état bloqué.',
            ];
        }

        $finalScore = min(100, max(0, $score));

        return [
            'score'   => $finalScore,
            'level'   => $this->level($finalScore),
            'factors' => $factors,
        ];
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function detectStructuring(Wallet $wallet): int
    {
        $thresholds = [1000.0, 5000.0, 10000.0];
        $count = 0;
        foreach ($wallet->getTransactions() as $tx) {
            $amount = abs((float) $tx->getMontant());
            foreach ($thresholds as $t) {
                if ($amount >= ($t * 0.85) && $amount < $t) {
                    ++$count;
                    break;
                }
            }
        }
        if ($count >= 5) return 15;
        if ($count >= 3) return 10;
        if ($count >= 2) return 5;
        return 0;
    }

    private function detectRoundAmounts(array $analytics): int
    {
        $transactions = $analytics['transactions'] ?? [];
        if (count($transactions) < 3) return 0;

        $roundCount = 0;
        foreach ($transactions as $tx) {
            $amount = abs((float) ($tx['amount'] ?? 0));
            if ($amount >= 500 && fmod($amount, 500.0) === 0.0) {
                ++$roundCount;
            }
        }
        $total = count($transactions);
        $ratio = $roundCount / $total;

        if ($ratio >= 0.50 && $roundCount >= 4) return 8;
        if ($ratio >= 0.35 && $roundCount >= 3) return 5;
        return 0;
    }

    private function detectDormantReactivation(Wallet $wallet, float $activityIndex): int
    {
        $transactions = $wallet->getTransactions()->toArray();
        if (count($transactions) < 3) return 0;

        usort($transactions, static fn($a, $b) => $a->getDateTransaction() <=> $b->getDateTransaction());

        $longestGap = 0;
        $prevDate = null;
        foreach ($transactions as $tx) {
            $d = $tx->getDateTransaction();
            if ($prevDate !== null) {
                $gap = (int) abs((int) $prevDate->diff($d)->days);
                $longestGap = max($longestGap, $gap);
            }
            $prevDate = $d;
        }

        if ($longestGap >= 60 && $activityIndex >= 1.5) return 10;
        if ($longestGap >= 30 && $activityIndex >= 1.8) return 7;
        if ($longestGap >= 30 && $activityIndex >= 1.3) return 4;
        return 0;
    }

    private function level(int $score): string
    {
        if ($score >= 76) return 'critique';
        if ($score >= 51) return 'eleve';
        if ($score >= 26) return 'moyen';
        return 'faible';
    }
}
