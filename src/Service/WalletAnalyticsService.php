<?php

namespace App\Service;

use App\Entity\Wallet\Cheque;
use App\Entity\Wallet\Transaction;
use App\Entity\Wallet\Wallet;
use Doctrine\ORM\EntityManagerInterface;

class WalletAnalyticsService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function buildAnalytics(Wallet $wallet): array
    {
        $transactions = $this->getWalletTransactions($wallet);
        $cheques = $this->getWalletCheques($wallet);
        $now = new \DateTimeImmutable();

        $last24hThreshold = $now->modify('-24 hours');
        $last7dThreshold = $now->modify('-7 days');
        $previous7dThreshold = $now->modify('-14 days');
        $last30dThreshold = $now->modify('-30 days');
        $previous30dThreshold = $now->modify('-60 days');

        $counts = [
            'total' => count($transactions),
            'last24h' => 0,
            'last7d' => 0,
            'last30d' => 0,
        ];

        $amounts = [];
        $signedAmounts = [];
        $transactionRows = [];
        $dailyVolumes = [];
        $typeStats = [];
        $nightTransactionCount = 0;
        $transactionsLast7d = [];
        $transactionsPrevious7d = [];
        $transactionsLast30d = [];
        $transactionsPrevious30d = [];

        foreach ($transactions as $transaction) {
            $type = $this->normalizeTransactionType($transaction->getType());
            $amount = round((float) $transaction->getMontant(), 2);
            $signedAmount = $this->toSignedAmount($type, $amount);
            $date = \DateTimeImmutable::createFromInterface($transaction->getDateTransaction());
            $hour = (int) $date->format('G');
            $dayKey = $date->format('Y-m-d');

            $amounts[] = $amount;
            $signedAmounts[] = $signedAmount;

            if (!isset($typeStats[$type])) {
                $typeStats[$type] = ['count' => 0, 'amount' => 0.0];
            }

            $typeStats[$type]['count']++;
            $typeStats[$type]['amount'] += $amount;

            if (!isset($dailyVolumes[$dayKey])) {
                $dailyVolumes[$dayKey] = [
                    'date' => $dayKey,
                    'count' => 0,
                    'gross_amount' => 0.0,
                    'net_amount' => 0.0,
                ];
            }

            $dailyVolumes[$dayKey]['count']++;
            $dailyVolumes[$dayKey]['gross_amount'] += $amount;
            $dailyVolumes[$dayKey]['net_amount'] += $signedAmount;

            if ($this->isNightHour($hour)) {
                $nightTransactionCount++;
            }

            if ($date >= $last24hThreshold) {
                $counts['last24h']++;
            }

            if ($date >= $last7dThreshold) {
                $counts['last7d']++;
                $transactionsLast7d[] = $transaction;
            } elseif ($date >= $previous7dThreshold) {
                $transactionsPrevious7d[] = $transaction;
            }

            if ($date >= $last30dThreshold) {
                $counts['last30d']++;
                $transactionsLast30d[] = $transaction;
            } elseif ($date >= $previous30dThreshold) {
                $transactionsPrevious30d[] = $transaction;
            }

            $transactionRows[] = [
                'id' => $transaction->getIdTransaction(),
                'type' => $type,
                'amount' => $amount,
                'signed_amount' => round($signedAmount, 2),
                'description' => $transaction->getDescription(),
                'date' => $date->format('Y-m-d H:i:s'),
                'timestamp' => $date->getTimestamp(),
                'hour' => $hour,
            ];
        }

        $totalAmount = round(array_sum($amounts), 2);
        $averageAmount = $counts['total'] > 0 ? round($totalAmount / $counts['total'], 2) : 0.0;
        $minAmount = $counts['total'] > 0 ? round(min($amounts), 2) : 0.0;
        $maxAmount = $counts['total'] > 0 ? round(max($amounts), 2) : 0.0;
        $signedAverage = $counts['total'] > 0 ? round(array_sum($signedAmounts) / $counts['total'], 2) : 0.0;

        $rapidSequences = $this->detectRapidSequences($transactions);
        $recentActivityIndicator = $this->buildRecentActivityIndicator($transactionsLast30d, $transactionsPrevious30d);
        $chequeStats = $this->buildChequeStats($cheques);
        $typeBreakdown = $this->finalizeTypeStats($typeStats, $counts['total'], $totalAmount);
        $dailyVolumeRows = $this->finalizeDailyVolumes($dailyVolumes);

        $metrics = [
            'total_transactions' => $counts['total'],
            'transactions_last_24h' => $counts['last24h'],
            'transactions_last_7d' => $counts['last7d'],
            'transactions_last_30d' => $counts['last30d'],
            'average_transaction_amount' => $averageAmount,
            'min_transaction_amount' => $minAmount,
            'max_transaction_amount' => $maxAmount,
            'total_transaction_amount' => $totalAmount,
            'dominant_transaction_type' => $this->findDominantTransactionType($typeStats),
            'average_transactions_per_day' => $this->computeAverageTransactionsPerDay($transactions),
            'average_interval_hours' => $this->computeAverageIntervalHours($transactions),
            'night_transaction_count' => $nightTransactionCount,
            'rapid_transaction_count' => $this->countRapidTransactions($rapidSequences),
            'rapid_sequence_count' => count($rapidSequences),
            'total_cheques' => $chequeStats['total_cheques'],
            'refused_cheques' => $chequeStats['refused_cheques'],
            'cheque_rejection_rate' => $chequeStats['cheque_rejection_rate'],
            'is_blocked' => (bool) $wallet->getEstBloque(),
            'wallet_status' => (string) $wallet->getStatut(),
            'current_balance' => round((float) $wallet->getSolde(), 2),
            'signed_average_amount' => $signedAverage,
            'usual_activity_hour' => $this->findUsualActivityHour($transactionRows),
            'balance_volatility_index' => $this->computeBalanceVolatilityIndex($signedAmounts, $averageAmount),
            'balance_instability_index' => $this->computeBalanceVolatilityIndex($signedAmounts, $averageAmount),
            'activity_change_ratio_7d' => $this->computeWindowRatio(count($transactionsLast7d), count($transactionsPrevious7d)),
            'activity_change_ratio_30d' => $this->computeWindowRatio(count($transactionsLast30d), count($transactionsPrevious30d)),
            'withdrawal_to_deposit_amount_ratio' => $this->computeWithdrawalToDepositRatio($typeStats),
            'withdrawal_to_deposit_count_ratio' => $this->computeWithdrawalToDepositCountRatio($typeStats),
            'recent_activity_label' => $recentActivityIndicator['label'],
            'recent_vs_historical_activity_index' => $recentActivityIndicator['index'],
            'recent_vs_historical_recent_daily_avg' => $recentActivityIndicator['recent_daily_average'],
            'recent_vs_historical_historical_daily_avg' => $recentActivityIndicator['historical_daily_average'],
        ];

        return [
            'wallet' => [
                'id' => $wallet->getIdWallet(),
                'owner' => $wallet->getNomProprietaire(),
                'currency' => $wallet->getDevise(),
                'status' => $wallet->getStatut(),
                'is_blocked' => (bool) $wallet->getEstBloque(),
                'is_active' => (bool) $wallet->getEstActif(),
                'balance' => round((float) $wallet->getSolde(), 2),
                'failed_attempts' => (int) ($wallet->getTentativesEchouees() ?? 0),
                'created_at' => $wallet->getDateCreation()->format('Y-m-d H:i:s'),
            ],
            'metrics' => $metrics,
            'breakdown' => [
                'transaction_types' => $typeBreakdown,
                'cheques' => $chequeStats,
            ],
            'recent_activity_indicator' => $recentActivityIndicator,
            'rapid_sequences' => $rapidSequences,
            'daily_volumes' => $dailyVolumeRows,
            'recent_activity' => array_slice(array_reverse($transactionRows), 0, 10),
            'transactions' => $transactionRows,
        ];
    }

    /**
     * @return Transaction[]
     */
    private function getWalletTransactions(Wallet $wallet): array
    {
        return $this->entityManager->getRepository(Transaction::class)
            ->createQueryBuilder('t')
            ->andWhere('t.idWallet = :walletId')
            ->setParameter('walletId', $wallet->getIdWallet())
            ->orderBy('t.dateTransaction', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return Cheque[]
     */
    private function getWalletCheques(Wallet $wallet): array
    {
        return $this->entityManager->getRepository(Cheque::class)
            ->createQueryBuilder('c')
            ->andWhere('c.idWallet = :walletId')
            ->setParameter('walletId', $wallet->getIdWallet())
            ->orderBy('c.dateEmission', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @param Transaction[] $transactions
     * @return array<int, array<string, mixed>>
     */
    private function detectRapidSequences(array $transactions): array
    {
        $rapidSequences = [];
        $windowSeconds = 120;
        $start = 0;

        for ($end = 0, $length = count($transactions); $end < $length; $end++) {
            $endTimestamp = $transactions[$end]->getDateTransaction()->getTimestamp();

            while (
                $start < $end
                && $endTimestamp - $transactions[$start]->getDateTransaction()->getTimestamp() > $windowSeconds
            ) {
                $start++;
            }

            $count = $end - $start + 1;
            if ($count < 3) {
                continue;
            }

            $windowTransactions = array_slice($transactions, $start, $count);
            $first = $windowTransactions[0];
            $last = $windowTransactions[count($windowTransactions) - 1];

            $rapidSequences[] = [
                'count' => $count,
                'from' => $first->getDateTransaction()->format('Y-m-d H:i:s'),
                'to' => $last->getDateTransaction()->format('Y-m-d H:i:s'),
                'transaction_ids' => array_map(
                    static fn (Transaction $transaction): int => $transaction->getIdTransaction(),
                    $windowTransactions
                ),
            ];

            $start = $end;
        }

        return $rapidSequences;
    }

    /**
     * @param Transaction[] $windowTransactions
     * @param Transaction[] $historicalTransactions
     * @return array<string, mixed>
     */
    private function buildRecentActivityIndicator(array $windowTransactions, array $historicalTransactions): array
    {
        $recentCount = count($windowTransactions);
        $historicalCount = count($historicalTransactions);
        $recentDailyAverage = round($recentCount / 30, 2);
        $historicalDailyAverage = round($historicalCount / 30, 2);

        if ($historicalCount === 0) {
            $index = $recentCount > 0 ? 999.0 : 1.0;
        } else {
            $index = round(max($recentDailyAverage, 0.01) / max($historicalDailyAverage, 0.01), 2);
        }

        $label = match (true) {
            $recentCount === 0 => 'quasi nulle',
            $index >= 2.5 => 'hausse brutale',
            $index >= 1.4 => 'hausse nette',
            $index <= 0.6 && $historicalCount > 0 => 'fort recul',
            $index <= 0.85 && $historicalCount > 0 => 'recul modere',
            $recentCount >= 20 => 'tres soutenue',
            $recentCount >= 10 => 'soutenue',
            $recentCount >= 4 => 'moderee',
            default => 'faible',
        };

        return [
            'label' => $label,
            'index' => $index,
            'recent_daily_average' => $recentDailyAverage,
            'historical_daily_average' => $historicalDailyAverage,
            'recent_count_30d' => $recentCount,
            'historical_count_previous_30d' => $historicalCount,
        ];
    }

    /**
     * @param Cheque[] $cheques
     * @return array<string, mixed>
     */
    private function buildChequeStats(array $cheques): array
    {
        $totalCheques = count($cheques);
        $refusedCheques = 0;
        $acceptedCheques = 0;
        $pendingCheques = 0;

        foreach ($cheques as $cheque) {
            $status = mb_strtolower(trim($cheque->getStatut()));
            if ($status === 'refuse') {
                $refusedCheques++;
            } elseif ($status === 'accepte') {
                $acceptedCheques++;
            } else {
                $pendingCheques++;
            }
        }

        return [
            'total_cheques' => $totalCheques,
            'refused_cheques' => $refusedCheques,
            'accepted_cheques' => $acceptedCheques,
            'pending_cheques' => $pendingCheques,
            'cheque_rejection_rate' => $totalCheques > 0 ? round(($refusedCheques / $totalCheques) * 100, 2) : 0.0,
        ];
    }

    /**
     * @param Transaction[] $transactions
     */
    private function computeAverageTransactionsPerDay(array $transactions): float
    {
        if ($transactions === []) {
            return 0.0;
        }

        $firstDate = \DateTimeImmutable::createFromInterface($transactions[0]->getDateTransaction());
        $lastDate = \DateTimeImmutable::createFromInterface($transactions[count($transactions) - 1]->getDateTransaction());
        $days = max(1, ((int) $firstDate->diff($lastDate)->days) + 1);

        return round(count($transactions) / $days, 2);
    }

    /**
     * @param Transaction[] $transactions
     */
    private function computeAverageIntervalHours(array $transactions): ?float
    {
        if (count($transactions) < 2) {
            return null;
        }

        $intervalSeconds = 0;
        for ($i = 1, $length = count($transactions); $i < $length; $i++) {
            $intervalSeconds += max(
                0,
                $transactions[$i]->getDateTransaction()->getTimestamp() - $transactions[$i - 1]->getDateTransaction()->getTimestamp()
            );
        }

        return round(($intervalSeconds / (count($transactions) - 1)) / 3600, 2);
    }

    /**
     * @param array<int, array<string, mixed>> $transactionRows
     */
    private function findUsualActivityHour(array $transactionRows): ?string
    {
        if ($transactionRows === []) {
            return null;
        }

        $hours = [];
        foreach ($transactionRows as $row) {
            $hour = (int) ($row['hour'] ?? 0);
            $hours[$hour] = ($hours[$hour] ?? 0) + 1;
        }

        arsort($hours);
        $mostFrequentHour = (int) array_key_first($hours);

        return sprintf('%02dh00-%02dh59', $mostFrequentHour, $mostFrequentHour);
    }

    /**
     * @param array<string, array{count:int, amount:float}> $typeStats
     */
    private function findDominantTransactionType(array $typeStats): ?string
    {
        if ($typeStats === []) {
            return null;
        }

        uasort($typeStats, static function (array $left, array $right): int {
            return [$right['count'], $right['amount']] <=> [$left['count'], $left['amount']];
        });

        return (string) array_key_first($typeStats);
    }

    /**
     * @param float[] $signedAmounts
     */
    private function computeBalanceVolatilityIndex(array $signedAmounts, float $averageAmount): float
    {
        if ($signedAmounts === [] || $averageAmount <= 0.0) {
            return 0.0;
        }

        $absoluteAverage = array_sum(array_map('abs', $signedAmounts)) / max(count($signedAmounts), 1);
        $stdDeviation = $this->computeStandardDeviation($signedAmounts);

        return round($stdDeviation / max($absoluteAverage, $averageAmount, 1.0), 2);
    }

    /**
     * @param float[] $values
     */
    private function computeStandardDeviation(array $values): float
    {
        if (count($values) < 2) {
            return 0.0;
        }

        $mean = array_sum($values) / count($values);
        $variance = 0.0;
        foreach ($values as $value) {
            $variance += ($value - $mean) ** 2;
        }

        return sqrt($variance / count($values));
    }

    private function computeWindowRatio(int $currentCount, int $previousCount): float
    {
        if ($previousCount === 0) {
            return $currentCount > 0 ? 999.0 : 1.0;
        }

        return round($currentCount / $previousCount, 2);
    }

    /**
     * @param array<string, array{count:int, amount:float}> $typeStats
     */
    private function computeWithdrawalToDepositRatio(array $typeStats): float
    {
        $withdrawalAmount = (float) ($typeStats['retrait']['amount'] ?? 0.0);
        $depositAmount = (float) ($typeStats['depot']['amount'] ?? 0.0);

        if ($depositAmount <= 0.0) {
            return $withdrawalAmount > 0.0 ? 999.0 : 0.0;
        }

        return round($withdrawalAmount / $depositAmount, 2);
    }

    /**
     * @param array<string, array{count:int, amount:float}> $typeStats
     */
    private function computeWithdrawalToDepositCountRatio(array $typeStats): float
    {
        $withdrawalCount = (int) ($typeStats['retrait']['count'] ?? 0);
        $depositCount = (int) ($typeStats['depot']['count'] ?? 0);

        if ($depositCount === 0) {
            return $withdrawalCount > 0 ? 999.0 : 0.0;
        }

        return round($withdrawalCount / $depositCount, 2);
    }

    /**
     * @param array<string, array{count:int, amount:float}> $typeStats
     * @return array<string, array<string, float|int>>
     */
    private function finalizeTypeStats(array $typeStats, int $totalTransactions, float $totalAmount): array
    {
        $knownTypes = ['depot', 'retrait', 'transfert', 'autre'];
        foreach ($knownTypes as $type) {
            if (!isset($typeStats[$type])) {
                $typeStats[$type] = ['count' => 0, 'amount' => 0.0];
            }
        }

        ksort($typeStats);

        foreach ($typeStats as $type => $stats) {
            $count = (int) $stats['count'];
            $amount = round((float) $stats['amount'], 2);

            $typeStats[$type] = [
                'count' => $count,
                'amount' => $amount,
                'ratio_count' => $totalTransactions > 0 ? round(($count / $totalTransactions) * 100, 2) : 0.0,
                'ratio_amount' => $totalAmount > 0 ? round(($amount / $totalAmount) * 100, 2) : 0.0,
            ];
        }

        return $typeStats;
    }

    /**
     * @param array<string, array<string, float|int|string>> $dailyVolumes
     * @return array<int, array<string, mixed>>
     */
    private function finalizeDailyVolumes(array $dailyVolumes): array
    {
        ksort($dailyVolumes);

        return array_values(array_map(static function (array $row): array {
            return [
                'date' => (string) $row['date'],
                'count' => (int) $row['count'],
                'gross_amount' => round((float) $row['gross_amount'], 2),
                'net_amount' => round((float) $row['net_amount'], 2),
            ];
        }, $dailyVolumes));
    }

    /**
     * @param array<int, array<string, mixed>> $rapidSequences
     */
    private function countRapidTransactions(array $rapidSequences): int
    {
        $total = 0;
        foreach ($rapidSequences as $sequence) {
            $total += (int) ($sequence['count'] ?? 0);
        }

        return $total;
    }

    private function normalizeTransactionType(string $type): string
    {
        $normalized = mb_strtolower(trim($type));

        return match ($normalized) {
            'depot', 'retail depot' => 'depot',
            'retrait', 'withdrawal' => 'retrait',
            'transfert', 'transfer', 'virement' => 'transfert',
            default => $normalized !== '' ? $normalized : 'autre',
        };
    }

    private function isNightHour(int $hour): bool
    {
        return $hour >= 22 || $hour < 6;
    }

    private function toSignedAmount(string $type, float $amount): float
    {
        return in_array($type, ['retrait', 'transfert'], true) ? -$amount : $amount;
    }
}
