<?php

namespace App\Service;

use App\Entity\Wallet\Wallet;

class AnomalyDetectionService
{
    public function __construct(
        private readonly WalletAnalyticsService $walletAnalyticsService,
    ) {
    }

    /**
     * @param array<string, mixed>|null $analytics
     * @return array<string, mixed>
     */
    public function detectAnomalies(Wallet $wallet, ?array $analytics = null): array
    {
        $analytics ??= $this->walletAnalyticsService->buildAnalytics($wallet);
        $metrics = is_array($analytics['metrics'] ?? null) ? $analytics['metrics'] : [];
        $transactions = is_array($analytics['transactions'] ?? null) ? $analytics['transactions'] : [];
        $rapidSequences = is_array($analytics['rapid_sequences'] ?? null) ? $analytics['rapid_sequences'] : [];
        $dailyVolumes = is_array($analytics['daily_volumes'] ?? null) ? $analytics['daily_volumes'] : [];
        $recentIndicator = is_array($analytics['recent_activity_indicator'] ?? null) ? $analytics['recent_activity_indicator'] : [];
        $typeBreakdown = is_array($analytics['breakdown']['transaction_types'] ?? null) ? $analytics['breakdown']['transaction_types'] : [];

        $anomalies = [];

        $rapid = $this->detectRapidTransactions($rapidSequences);
        if ($rapid !== null) {
            $anomalies[] = $rapid;
        }

        $night = $this->detectAbnormalNightActivity($metrics, $transactions);
        if ($night !== null) {
            $anomalies[] = $night;
        }

        $unusualAmounts = $this->detectUnusualAmounts($metrics, $transactions);
        if ($unusualAmounts !== null) {
            $anomalies[] = $unusualAmounts;
        }

        $volumeSpike = $this->detectVolumeSpike($dailyVolumes, $recentIndicator);
        if ($volumeSpike !== null) {
            $anomalies[] = $volumeSpike;
        }

        $withdrawals = $this->detectWithdrawalPressure($metrics, $typeBreakdown);
        if ($withdrawals !== null) {
            $anomalies[] = $withdrawals;
        }

        $cheques = $this->detectChequeRejections($metrics);
        if ($cheques !== null) {
            $anomalies[] = $cheques;
        }

        $volatility = $this->detectBalanceInstability($metrics, $dailyVolumes);
        if ($volatility !== null) {
            $anomalies[] = $volatility;
        }

        return [
            'anomalies_detected' => $anomalies,
            'severity' => $this->computeOverallSeverity($anomalies),
            'details' => array_map(
                static fn (array $anomaly): string => (string) ($anomaly['details'] ?? ''),
                $anomalies
            ),
            'count' => count($anomalies),
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $rapidSequences
     * @return array<string, mixed>|null
     */
    private function detectRapidTransactions(array $rapidSequences): ?array
    {
        if ($rapidSequences === []) {
            return null;
        }

        usort($rapidSequences, static fn (array $left, array $right): int => ((int) ($right['count'] ?? 0)) <=> ((int) ($left['count'] ?? 0)));
        $sequence = $rapidSequences[0];
        $count = (int) ($sequence['count'] ?? 0);

        return [
            'code' => 'rapid_transactions',
            'title' => 'Transactions trop rapides',
            'severity' => $count >= 5 ? 'critical' : 'high',
            'details' => sprintf('%d transactions ou plus ont ete executees en moins de 2 minutes.', $count),
            'examples' => [[
                'from' => (string) ($sequence['from'] ?? ''),
                'to' => (string) ($sequence['to'] ?? ''),
                'transaction_ids' => $sequence['transaction_ids'] ?? [],
            ]],
            'timestamps' => array_values(array_filter([
                $sequence['from'] ?? null,
                $sequence['to'] ?? null,
            ])),
        ];
    }

    /**
     * @param array<string, mixed> $metrics
     * @param array<int, array<string, mixed>> $transactions
     * @return array<string, mixed>|null
     */
    private function detectAbnormalNightActivity(array $metrics, array $transactions): ?array
    {
        $nightCount = (int) ($metrics['night_transaction_count'] ?? 0);
        $total = (int) ($metrics['total_transactions'] ?? 0);
        if ($nightCount < 2 || $total === 0) {
            return null;
        }

        $nightRatio = $nightCount / $total;
        if ($nightRatio < 0.30 && $nightCount < 4) {
            return null;
        }

        $examples = [];
        foreach ($transactions as $transaction) {
            $hour = (int) ($transaction['hour'] ?? -1);
            if ($hour >= 22 || $hour < 6) {
                $examples[] = [
                    'id' => $transaction['id'] ?? null,
                    'type' => $transaction['type'] ?? null,
                    'amount' => $transaction['amount'] ?? null,
                    'timestamp' => $transaction['date'] ?? null,
                ];
            }

            if (count($examples) >= 3) {
                break;
            }
        }

        return [
            'code' => 'abnormal_night_activity',
            'title' => 'Activite nocturne anormale',
            'severity' => $nightRatio >= 0.45 ? 'high' : 'medium',
            'details' => sprintf('Le wallet presente %d transaction(s) nocturne(s), soit %.0f%% de son activite.', $nightCount, $nightRatio * 100),
            'examples' => $examples,
            'timestamps' => array_values(array_filter(array_map(static fn (array $item): ?string => is_string($item['timestamp'] ?? null) ? $item['timestamp'] : null, $examples))),
        ];
    }

    /**
     * @param array<string, mixed> $metrics
     * @param array<int, array<string, mixed>> $transactions
     * @return array<string, mixed>|null
     */
    private function detectUnusualAmounts(array $metrics, array $transactions): ?array
    {
        $average = (float) ($metrics['average_transaction_amount'] ?? 0.0);
        $max = (float) ($metrics['max_transaction_amount'] ?? 0.0);
        if ($average <= 0.0 || $max <= 0.0) {
            return null;
        }

        $threshold = max($average * 2.8, $average + 250.0);
        $flagged = [];

        foreach ($transactions as $transaction) {
            $amount = (float) ($transaction['amount'] ?? 0.0);
            if ($amount >= $threshold) {
                $flagged[] = [
                    'id' => $transaction['id'] ?? null,
                    'amount' => $amount,
                    'type' => $transaction['type'] ?? null,
                    'timestamp' => $transaction['date'] ?? null,
                ];
            }

            if (count($flagged) >= 3) {
                break;
            }
        }

        if ($flagged === []) {
            return null;
        }

        return [
            'code' => 'unusual_amount',
            'title' => 'Montants inhabituels',
            'severity' => $max >= ($threshold * 1.6) ? 'high' : 'medium',
            'details' => sprintf('Des transactions depassent le seuil de vigilance de %.2f, au-dessus de la moyenne historique du wallet.', $threshold),
            'examples' => $flagged,
            'timestamps' => array_values(array_filter(array_map(static fn (array $item): ?string => is_string($item['timestamp'] ?? null) ? $item['timestamp'] : null, $flagged))),
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $dailyVolumes
     * @param array<string, mixed> $recentIndicator
     * @return array<string, mixed>|null
     */
    private function detectVolumeSpike(array $dailyVolumes, array $recentIndicator): ?array
    {
        $activityIndex = (float) ($recentIndicator['index'] ?? 1.0);
        if ($activityIndex < 1.8) {
            return null;
        }

        $examples = array_slice(array_reverse($dailyVolumes), 0, 3);

        return [
            'code' => 'sudden_activity_change',
            'title' => 'Hausse brutale du volume de transactions',
            'severity' => $activityIndex >= 2.8 ? 'high' : 'medium',
            'details' => sprintf(
                'L activite recente est %.2fx superieure a la periode historique precedente.',
                $activityIndex
            ),
            'examples' => $examples,
            'timestamps' => array_values(array_filter(array_map(static fn (array $row): ?string => is_string($row['date'] ?? null) ? $row['date'] : null, $examples))),
        ];
    }

    /**
     * @param array<string, mixed> $metrics
     * @param array<string, mixed> $typeBreakdown
     * @return array<string, mixed>|null
     */
    private function detectWithdrawalPressure(array $metrics, array $typeBreakdown): ?array
    {
        $ratioAmount = (float) ($metrics['withdrawal_to_deposit_amount_ratio'] ?? 0.0);
        $ratioCount = (float) ($metrics['withdrawal_to_deposit_count_ratio'] ?? 0.0);
        $withdrawalCount = (int) ($typeBreakdown['retrait']['count'] ?? 0);

        if ($withdrawalCount < 3 || ($ratioAmount < 1.7 && $ratioCount < 1.7)) {
            return null;
        }

        return [
            'code' => 'withdrawal_imbalance',
            'title' => 'Retraits excessifs par rapport aux depots',
            'severity' => max($ratioAmount, $ratioCount) >= 3.0 ? 'high' : 'medium',
            'details' => sprintf(
                'Les retraits dominent les depots avec un ratio montant de %.2f et un ratio volumique de %.2f.',
                $ratioAmount,
                $ratioCount
            ),
            'examples' => [[
                'withdrawal_count' => $withdrawalCount,
                'withdrawal_amount' => $typeBreakdown['retrait']['amount'] ?? 0.0,
                'deposit_amount' => $typeBreakdown['depot']['amount'] ?? 0.0,
            ]],
            'timestamps' => [],
        ];
    }

    /**
     * @param array<string, mixed> $metrics
     * @return array<string, mixed>|null
     */
    private function detectChequeRejections(array $metrics): ?array
    {
        $refused = (int) ($metrics['refused_cheques'] ?? 0);
        $rate = (float) ($metrics['cheque_rejection_rate'] ?? 0.0);

        if ($refused < 2 && $rate < 25.0) {
            return null;
        }

        return [
            'code' => 'multiple_refused_cheques',
            'title' => 'Trop de cheques refuses',
            'severity' => $refused >= 4 || $rate >= 40.0 ? 'high' : 'medium',
            'details' => sprintf('%d cheque(s) refuse(s) ont ete releves, avec un taux de rejet de %.2f%%.', $refused, $rate),
            'examples' => [[
                'refused_cheques' => $refused,
                'rejection_rate' => $rate,
            ]],
            'timestamps' => [],
        ];
    }

    /**
     * @param array<string, mixed> $metrics
     * @param array<int, array<string, mixed>> $dailyVolumes
     * @return array<string, mixed>|null
     */
    private function detectBalanceInstability(array $metrics, array $dailyVolumes): ?array
    {
        $instability = (float) ($metrics['balance_instability_index'] ?? 0.0);
        if ($instability < 0.9) {
            return null;
        }

        $examples = array_slice(array_reverse($dailyVolumes), 0, 3);

        return [
            'code' => 'balance_instability',
            'title' => 'Solde instable ou fortement variable',
            'severity' => $instability >= 1.4 ? 'high' : 'medium',
            'details' => sprintf('L indice de volatilite du solde atteint %.2f, au-dessus du seuil de stabilite attendu.', $instability),
            'examples' => $examples,
            'timestamps' => array_values(array_filter(array_map(static fn (array $row): ?string => is_string($row['date'] ?? null) ? $row['date'] : null, $examples))),
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $anomalies
     */
    private function computeOverallSeverity(array $anomalies): string
    {
        $levels = array_map(static fn (array $anomaly): string => (string) ($anomaly['severity'] ?? 'low'), $anomalies);

        if (in_array('critical', $levels, true)) {
            return 'critical';
        }
        if (in_array('high', $levels, true)) {
            return 'high';
        }
        if (in_array('medium', $levels, true)) {
            return 'medium';
        }

        return 'low';
    }
}
