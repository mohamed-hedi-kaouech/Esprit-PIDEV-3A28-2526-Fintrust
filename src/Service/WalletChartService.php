<?php

namespace App\Service;

use App\Entity\Wallet\Wallet;
use Symfony\UX\Chartjs\Builder\ChartBuilderInterface;
use Symfony\UX\Chartjs\Model\Chart;

class WalletChartService
{
    public function __construct(
        private readonly ChartBuilderInterface $chartBuilder,
    ) {
    }

    /**
     * @param array<string, mixed> $analytics
     * @param array<string, mixed> $riskAnalysis
     * @return array<string, Chart>
     */
    public function buildWalletCharts(Wallet $wallet, array $analytics, array $riskAnalysis): array
    {
        $dailyActivity = $this->buildDailyActivityRows($analytics);
        $typeBreakdown = is_array($analytics['breakdown']['transaction_types'] ?? null) ? $analytics['breakdown']['transaction_types'] : [];
        $chequeStats = is_array($analytics['breakdown']['cheques'] ?? null) ? $analytics['breakdown']['cheques'] : [];

        return [
            'dailyTransactions' => $this->buildDailyTransactionsChart($dailyActivity),
            'transactionTypes' => $this->buildTransactionTypesChart($typeBreakdown),
            'depositWithdrawal' => $this->buildDepositWithdrawalChart($wallet, $typeBreakdown),
            'riskTrend' => $this->buildRiskTrendChart($dailyActivity, $riskAnalysis, $chequeStats),
            'chequeStatus' => $this->buildChequeStatusChart($chequeStats),
        ];
    }

    /**
     * @param array<string, mixed> $analytics
     * @return array<int, array<string, mixed>>
     */
    private function buildDailyActivityRows(array $analytics): array
    {
        $dailyVolumes = is_array($analytics['daily_volumes'] ?? null) ? $analytics['daily_volumes'] : [];
        $indexed = [];

        foreach ($dailyVolumes as $row) {
            if (!is_array($row) || !isset($row['date'])) {
                continue;
            }

            $indexed[(string) $row['date']] = [
                'date' => (string) $row['date'],
                'count' => (int) ($row['count'] ?? 0),
                'gross_amount' => round((float) ($row['gross_amount'] ?? 0.0), 2),
                'net_amount' => round((float) ($row['net_amount'] ?? 0.0), 2),
            ];
        }

        $rows = [];
        $today = new \DateTimeImmutable('today');
        for ($offset = 6; $offset >= 0; $offset--) {
            $day = $today->modify(sprintf('-%d days', $offset));
            $key = $day->format('Y-m-d');
            $rows[] = $indexed[$key] ?? [
                'date' => $key,
                'count' => 0,
                'gross_amount' => 0.0,
                'net_amount' => 0.0,
            ];
        }

        return $rows;
    }

    /**
     * @param array<int, array<string, mixed>> $dailyActivity
     */
    private function buildDailyTransactionsChart(array $dailyActivity): Chart
    {
        $chart = $this->chartBuilder->createChart(Chart::TYPE_LINE);
        $chart->setData([
            'labels' => array_map(
                static fn (array $row): string => (new \DateTimeImmutable((string) $row['date']))->format('d/m'),
                $dailyActivity
            ),
            'datasets' => [[
                'label' => 'Transactions / jour',
                'data' => array_map(static fn (array $row): int => (int) $row['count'], $dailyActivity),
                'borderColor' => '#1d4ed8',
                'backgroundColor' => 'rgba(37, 99, 235, 0.14)',
                'fill' => true,
                'tension' => 0.35,
                'pointBackgroundColor' => '#1d4ed8',
                'pointBorderColor' => '#ffffff',
                'pointHoverRadius' => 6,
            ]],
        ]);
        $chart->setOptions($this->buildCartesianOptions('Volume transactionnel sur 7 jours'));

        return $chart;
    }

    /**
     * @param array<string, array<string, float|int>> $typeBreakdown
     */
    private function buildTransactionTypesChart(array $typeBreakdown): Chart
    {
        $palette = [
            'depot' => '#16a34a',
            'retrait' => '#dc2626',
            'transfert' => '#2563eb',
            'autre' => '#f59e0b',
        ];

        $chart = $this->chartBuilder->createChart(Chart::TYPE_DOUGHNUT);
        $chart->setData([
            'labels' => array_map(static fn (string $type): string => ucfirst($type), array_keys($typeBreakdown)),
            'datasets' => [[
                'label' => 'Transactions par type',
                'data' => array_map(static fn (array $stats): int => (int) ($stats['count'] ?? 0), array_values($typeBreakdown)),
                'backgroundColor' => array_map(
                    static fn (string $type): string => $palette[$type] ?? '#94a3b8',
                    array_keys($typeBreakdown)
                ),
                'borderColor' => '#ffffff',
                'borderWidth' => 2,
                'hoverOffset' => 8,
            ]],
        ]);
        $chart->setOptions($this->buildCircularOptions('Repartition des transactions par type'));

        return $chart;
    }

    /**
     * @param array<string, array<string, float|int>> $typeBreakdown
     */
    private function buildDepositWithdrawalChart(Wallet $wallet, array $typeBreakdown): Chart
    {
        $chart = $this->chartBuilder->createChart(Chart::TYPE_BAR);
        $chart->setData([
            'labels' => ['Depot', 'Retrait'],
            'datasets' => [[
                'label' => 'Montant (' . $wallet->getDevise() . ')',
                'data' => [
                    round((float) ($typeBreakdown['depot']['amount'] ?? 0.0), 2),
                    round((float) ($typeBreakdown['retrait']['amount'] ?? 0.0), 2),
                ],
                'backgroundColor' => ['#16a34a', '#dc2626'],
                'borderRadius' => 14,
                'borderSkipped' => false,
                'maxBarThickness' => 48,
            ]],
        ]);
        $chart->setOptions($this->buildCartesianOptions('Volume depot vs retrait'));

        return $chart;
    }

    /**
     * @param array<int, array<string, mixed>> $dailyActivity
     * @param array<string, mixed> $riskAnalysis
     * @param array<string, mixed> $chequeStats
     */
    private function buildRiskTrendChart(array $dailyActivity, array $riskAnalysis, array $chequeStats): Chart
    {
        $currentScore = max(0, (int) ($riskAnalysis['score'] ?? 0));
        $maxCount = max(1, ...array_map(static fn (array $row): int => (int) $row['count'], $dailyActivity));
        $maxGross = max(1.0, ...array_map(static fn (array $row): float => (float) $row['gross_amount'], $dailyActivity));
        $chequeRiskBoost = min(10, ((int) ($chequeStats['refused_cheques'] ?? 0) * 3) + ((int) ($chequeStats['pending_cheques'] ?? 0)));

        $scores = [];
        foreach ($dailyActivity as $row) {
            $count = (int) ($row['count'] ?? 0);
            $gross = (float) ($row['gross_amount'] ?? 0.0);
            $net = (float) ($row['net_amount'] ?? 0.0);
            $activityFactor = (($count / $maxCount) * 0.55) + (($gross / $maxGross) * 0.45);
            $dayScore = ($currentScore * (0.32 + (0.68 * $activityFactor)))
                + ($count >= 3 ? 5 : 0)
                + ($net < 0 ? 6 : 0)
                + (($gross > 0.0 && abs($net) / $gross >= 0.7) ? 4 : 0)
                + $chequeRiskBoost;

            $scores[] = min(100, max($count > 0 ? 8 : 3, (int) round($dayScore)));
        }

        $chart = $this->chartBuilder->createChart(Chart::TYPE_LINE);
        $chart->setData([
            'labels' => array_map(
                static fn (array $row): string => (new \DateTimeImmutable((string) $row['date']))->format('d/m'),
                $dailyActivity
            ),
            'datasets' => [[
                'label' => 'Score de risque journalier',
                'data' => $scores,
                'borderColor' => '#b91c1c',
                'backgroundColor' => 'rgba(220, 38, 38, 0.12)',
                'fill' => true,
                'tension' => 0.3,
                'pointBackgroundColor' => '#b91c1c',
                'pointHoverRadius' => 6,
            ]],
        ]);
        $chart->setOptions(array_replace_recursive(
            $this->buildCartesianOptions('Projection du score de risque sur 7 jours'),
            ['scales' => ['y' => ['suggestedMax' => 100]]]
        ));

        return $chart;
    }

    /**
     * @param array<string, mixed> $chequeStats
     */
    private function buildChequeStatusChart(array $chequeStats): Chart
    {
        $chart = $this->chartBuilder->createChart(Chart::TYPE_PIE);
        $chart->setData([
            'labels' => ['Acceptes', 'Refuses', 'En attente'],
            'datasets' => [[
                'label' => 'Statut des cheques',
                'data' => [
                    (int) ($chequeStats['accepted_cheques'] ?? 0),
                    (int) ($chequeStats['refused_cheques'] ?? 0),
                    (int) ($chequeStats['pending_cheques'] ?? 0),
                ],
                'backgroundColor' => ['#16a34a', '#dc2626', '#f59e0b'],
                'borderColor' => '#ffffff',
                'borderWidth' => 2,
            ]],
        ]);
        $chart->setOptions($this->buildCircularOptions('Repartition des cheques'));

        return $chart;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildCartesianOptions(string $title): array
    {
        return [
            'responsive' => true,
            'maintainAspectRatio' => false,
            'plugins' => [
                'legend' => [
                    'display' => true,
                    'position' => 'bottom',
                ],
                'title' => [
                    'display' => true,
                    'text' => $title,
                ],
            ],
            'scales' => [
                'y' => [
                    'beginAtZero' => true,
                    'ticks' => ['color' => '#64748b'],
                    'grid' => ['color' => 'rgba(148, 163, 184, 0.14)'],
                ],
                'x' => [
                    'ticks' => ['color' => '#64748b'],
                    'grid' => ['display' => false],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildCircularOptions(string $title): array
    {
        return [
            'responsive' => true,
            'maintainAspectRatio' => false,
            'plugins' => [
                'legend' => [
                    'display' => true,
                    'position' => 'bottom',
                ],
                'title' => [
                    'display' => true,
                    'text' => $title,
                ],
            ],
        ];
    }
}
