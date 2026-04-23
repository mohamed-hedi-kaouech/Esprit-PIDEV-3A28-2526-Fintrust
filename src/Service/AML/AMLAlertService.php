<?php

namespace App\Service\AML;

use App\Entity\Wallet\Wallet;

/**
 * Génère des alertes AML typées pour un wallet donné.
 * Chaque alerte est un tableau avec code, severity, title, description, detected_at, wallet_id.
 */
class AMLAlertService
{
    private const SEVERITY_ORDER = ['critical' => 0, 'high' => 1, 'medium' => 2, 'info' => 3];

    /**
     * @return list<array{code:string, severity:string, title:string, description:string, detected_at:\DateTimeImmutable, wallet_id:int}>
     */
    public function generateAlerts(Wallet $wallet, array $analytics, array $anomalyReport): array
    {
        $alerts   = [];
        $metrics  = is_array($analytics['metrics'] ?? null) ? $analytics['metrics'] : [];
        $walletId = $wallet->getIdWallet();
        $now      = new \DateTimeImmutable();

        // ── 1. Transactions en rafale ─────────────────────────────────────────
        $rapidCount = (int) ($metrics['rapid_transaction_count'] ?? 0);
        if ($rapidCount >= 3) {
            $alerts[] = $this->alert(
                'RAPID_SUCCESSION', 'critical',
                'Transactions en rafale détectées',
                sprintf('%d transactions en moins de 2 minutes. Pattern typique de fractionnement (layering) ou de test de compte.', $rapidCount),
                $now, $walletId
            );
        } elseif ($rapidCount >= 2) {
            $alerts[] = $this->alert(
                'RAPID_SUCCESSION', 'high',
                'Succession rapide de transactions',
                sprintf('%d transactions en intervalle très court.', $rapidCount),
                $now, $walletId
            );
        }

        // ── 2. Montant anormalement élevé ─────────────────────────────────────
        $avgAmount = (float) ($metrics['average_transaction_amount'] ?? 0);
        $maxAmount = (float) ($metrics['max_transaction_amount'] ?? 0);
        if ($avgAmount > 0 && $maxAmount >= $avgAmount * 3.0) {
            $alerts[] = $this->alert(
                'ABNORMAL_AMOUNT', 'high',
                'Montant anormalement élevé',
                sprintf('Transaction max %.2f TND vs moyenne %.2f TND (×%.1f).', $maxAmount, $avgAmount, $maxAmount / $avgAmount),
                $now, $walletId
            );
        }

        // ── 3. Hausse brutale du volume ───────────────────────────────────────
        $activityIndex = (float) ($metrics['recent_vs_historical_activity_index'] ?? 1.0);
        if ($activityIndex >= 3.0) {
            $alerts[] = $this->alert(
                'VOLUME_SPIKE', 'critical',
                'Hausse brutale du volume d\'activité',
                sprintf('Activité récente ×%.1f vs historique. Signal de placement ou de réactivation suspecte.', $activityIndex),
                $now, $walletId
            );
        } elseif ($activityIndex >= 1.8) {
            $alerts[] = $this->alert(
                'VOLUME_SPIKE', 'high',
                'Hausse notable du volume d\'activité',
                sprintf('Activité récente ×%.1f vs historique.', $activityIndex),
                $now, $walletId
            );
        }

        // ── 4. Activité nocturne ──────────────────────────────────────────────
        $nightCount = (int) ($metrics['night_transaction_count'] ?? 0);
        $totalTx    = max(1, (int) ($metrics['total_transactions'] ?? 1));
        if ($nightCount >= 3 && ($nightCount / $totalTx) >= 0.30) {
            $alerts[] = $this->alert(
                'NIGHT_ACTIVITY', 'high',
                'Activité nocturne inhabituelle',
                sprintf('%d transactions entre 22 h et 6 h (%.0f %% du total). Comportement d\'évitement de surveillance.', $nightCount, ($nightCount / $totalTx) * 100),
                $now, $walletId
            );
        } elseif ($nightCount >= 2) {
            $alerts[] = $this->alert(
                'NIGHT_ACTIVITY', 'medium',
                'Activité nocturne détectée',
                sprintf('%d transactions nocturnes détectées.', $nightCount),
                $now, $walletId
            );
        }

        // ── 5. Retraits excessifs ────────────────────────────────────────────
        $wdRatio = (float) ($metrics['withdrawal_to_deposit_amount_ratio'] ?? 1.0);
        if ($wdRatio >= 2.5) {
            $alerts[] = $this->alert(
                'EXCESSIVE_WITHDRAWALS', $wdRatio >= 3.5 ? 'critical' : 'high',
                'Retraits excessifs',
                sprintf('Volume de retraits %.1f× supérieur aux dépôts. Signal de layering ou d\'extraction.', $wdRatio),
                $now, $walletId
            );
        }

        // ── 6. Wallet dormant réactivé ────────────────────────────────────────
        $dormantDays = $this->computeLongestGap($wallet);
        if ($dormantDays >= 30 && $activityIndex >= 1.5) {
            $alerts[] = $this->alert(
                'DORMANT_REACTIVATION', 'high',
                'Wallet dormant réactivé',
                sprintf('Inactivité de %d jours puis hausse ×%.1f. Comportement typique de blanchiment (phase de placement).', $dormantDays, $activityIndex),
                $now, $walletId
            );
        }

        // ── 7. Signal de fraude chèque ────────────────────────────────────────
        $refusedCheques = (int) ($metrics['refused_cheques'] ?? 0);
        if ($refusedCheques >= 2) {
            $alerts[] = $this->alert(
                'CHEQUE_FRAUD_SIGNAL', $refusedCheques >= 3 ? 'critical' : 'high',
                'Signal de fraude par chèque',
                sprintf('%d chèques refusés enregistrés. Possible tentative de fraude ou d\'encaissement frauduleux.', $refusedCheques),
                $now, $walletId
            );
        }

        // ── 8. Fréquence anormale sur 24 h ───────────────────────────────────
        $txLast24h  = (int) ($metrics['transactions_last_24h'] ?? 0);
        $avgPerDay  = (float) ($metrics['average_transactions_per_day'] ?? 0);
        if ($avgPerDay > 0 && $txLast24h >= $avgPerDay * 3 && $txLast24h >= 5) {
            $alerts[] = $this->alert(
                'ABNORMAL_FREQUENCY', 'medium',
                'Fréquence anormale sur 24 h',
                sprintf('%d transactions en 24 h vs moyenne %.1f/jour (×%.1f).', $txLast24h, $avgPerDay, $txLast24h / $avgPerDay),
                $now, $walletId
            );
        }

        // ── 9. Comportement à surveiller (chèques en attente multiples) ──────
        $pendingCheques = 0;
        foreach ($wallet->getCheques() as $c) {
            if (strtolower($c->getStatut()) === 'en_attente') {
                ++$pendingCheques;
            }
        }
        if ($pendingCheques >= 3) {
            $alerts[] = $this->alert(
                'PENDING_CHEQUES_ACCUMULATION', 'medium',
                'Accumulation de chèques en attente',
                sprintf('%d chèques en attente simultanément. Comportement atypique à surveiller.', $pendingCheques),
                $now, $walletId
            );
        }

        usort($alerts, static fn($a, $b) => (self::SEVERITY_ORDER[$a['severity']] ?? 99) <=> (self::SEVERITY_ORDER[$b['severity']] ?? 99));

        return $alerts;
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function alert(string $code, string $severity, string $title, string $description, \DateTimeImmutable $at, int $walletId): array
    {
        return [
            'code'        => $code,
            'severity'    => $severity,
            'title'       => $title,
            'description' => $description,
            'detected_at' => $at,
            'wallet_id'   => $walletId,
        ];
    }

    private function computeLongestGap(Wallet $wallet): int
    {
        $transactions = $wallet->getTransactions()->toArray();
        if (count($transactions) < 2) return 0;

        usort($transactions, static fn($a, $b) => $a->getDateTransaction() <=> $b->getDateTransaction());

        $longestGap = 0;
        $prevDate   = null;
        foreach ($transactions as $tx) {
            $d = $tx->getDateTransaction();
            if ($prevDate !== null) {
                $gap = (int) abs((int) $prevDate->diff($d)->days);
                $longestGap = max($longestGap, $gap);
            }
            $prevDate = $d;
        }
        return $longestGap;
    }
}
