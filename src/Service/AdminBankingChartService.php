<?php

namespace App\Service;

use App\Entity\Wallet\Cheque;
use App\Entity\Wallet\Transaction;
use App\Entity\Wallet\Wallet;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\UX\Chartjs\Builder\ChartBuilderInterface;
use Symfony\UX\Chartjs\Model\Chart;

class AdminBankingChartService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ChartBuilderInterface $chartBuilder,
    ) {
    }

    /**
     * @return array<string, Chart>
     */
    public function buildDashboardCharts(): array
    {
        $dailyRows = $this->buildDailyRows();
        $typeRows = $this->buildTypeRows();
        $chequeRows = $this->buildChequeRows();
        $balanceRows = $this->buildBalanceRows();

        return [
            'transactionsByDay' => $this->lineChart(
                'Transactions par jour',
                array_column($dailyRows, 'label'),
                array_column($dailyRows, 'count'),
                '#2563eb',
                'Transactions'
            ),
            'transactionTypes' => $this->doughnutChart(
                'Repartition par type',
                array_keys($typeRows),
                array_column($typeRows, 'count'),
                ['#2563eb', '#16a34a', '#dc2626', '#f59e0b', '#64748b']
            ),
            'depositWithdrawal' => $this->barChart(
                'Depot vs retrait',
                ['Depot', 'Retrait'],
                [
                    (float) ($typeRows['depot']['amount'] ?? 0.0),
                    (float) ($typeRows['retrait']['amount'] ?? 0.0),
                ],
                ['#16a34a', '#dc2626'],
                'Montant'
            ),
            'riskTrend' => $this->lineChart(
                'Score de risque sur 7 jours',
                array_column($dailyRows, 'label'),
                array_column($dailyRows, 'risk_score'),
                '#b91c1c',
                'Risque'
            ),
            'chequeRefusalRate' => $this->doughnutChart(
                'Taux de refus des cheques',
                ['Acceptes/Livres', 'Refuses', 'En attente'],
                [
                    $chequeRows['accepted'],
                    $chequeRows['refused'],
                    $chequeRows['pending'],
                ],
                ['#16a34a', '#dc2626', '#f59e0b']
            ),
            'balanceEvolution' => $this->lineChart(
                'Evolution du solde total',
                array_column($balanceRows, 'label'),
                array_column($balanceRows, 'balance'),
                '#0f766e',
                'Solde total'
            ),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildDailyRows(): array
    {
        /** @var Transaction[] $transactions */
        $transactions = $this->entityManager->getRepository(Transaction::class)
            ->createQueryBuilder('t')
            ->andWhere('t.dateTransaction >= :start')
            ->setParameter('start', new \DateTimeImmutable('today -6 days'))
            ->orderBy('t.dateTransaction', 'ASC')
            ->getQuery()
            ->getResult();

        $rows = [];
        for ($i = 6; $i >= 0; $i--) {
            $date = (new \DateTimeImmutable('today'))->modify(sprintf('-%d days', $i));
            $rows[$date->format('Y-m-d')] = [
                'label' => $date->format('d/m'),
                'count' => 0,
                'volume' => 0.0,
                'night_count' => 0,
            ];
        }

        foreach ($transactions as $transaction) {
            $key = $transaction->getDateTransaction()->format('Y-m-d');
            if (!isset($rows[$key])) {
                continue;
            }

            $rows[$key]['count']++;
            $rows[$key]['volume'] += (float) $transaction->getMontant();
            $hour = (int) $transaction->getDateTransaction()->format('G');
            if ($hour >= 22 || $hour < 6) {
                $rows[$key]['night_count']++;
            }
        }

        foreach ($rows as &$row) {
            $row['risk_score'] = min(100, (int) (($row['count'] * 6) + ($row['night_count'] * 10) + ($row['volume'] >= 5000 ? 12 : 0)));
        }
        unset($row);

        return array_values($rows);
    }

    /**
     * @return array<string, array{count:int, amount:float}>
     */
    private function buildTypeRows(): array
    {
        $rows = [
            'depot' => ['count' => 0, 'amount' => 0.0],
            'retrait' => ['count' => 0, 'amount' => 0.0],
            'transfert' => ['count' => 0, 'amount' => 0.0],
            'autre' => ['count' => 0, 'amount' => 0.0],
        ];

        /** @var Transaction[] $transactions */
        $transactions = $this->entityManager->getRepository(Transaction::class)->findAll();
        foreach ($transactions as $transaction) {
            $type = mb_strtolower(trim($transaction->getType()));
            $type = isset($rows[$type]) ? $type : 'autre';
            $rows[$type]['count']++;
            $rows[$type]['amount'] += (float) $transaction->getMontant();
        }

        return $rows;
    }

    /**
     * @return array{accepted:int, refused:int, pending:int}
     */
    private function buildChequeRows(): array
    {
        $rows = ['accepted' => 0, 'refused' => 0, 'pending' => 0];

        /** @var Cheque[] $cheques */
        $cheques = $this->entityManager->getRepository(Cheque::class)->findAll();
        foreach ($cheques as $cheque) {
            $status = mb_strtolower($cheque->getStatut());
            if (in_array($status, ['accepte', 'livre'], true)) {
                $rows['accepted']++;
            } elseif ($status === 'refuse') {
                $rows['refused']++;
            } else {
                $rows['pending']++;
            }
        }

        return $rows;
    }

    /**
     * @return array<int, array{label:string, balance:float}>
     */
    private function buildBalanceRows(): array
    {
        $currentBalance = 0.0;
        /** @var Wallet[] $wallets */
        $wallets = $this->entityManager->getRepository(Wallet::class)->findAll();
        foreach ($wallets as $wallet) {
            $currentBalance += (float) $wallet->getSolde();
        }

        $dailyRows = $this->buildDailyRows();
        $balanceRows = [];
        $running = $currentBalance;
        foreach (array_reverse($dailyRows) as $row) {
            $balanceRows[] = ['label' => (string) $row['label'], 'balance' => round($running, 2)];
            $running -= (float) $row['volume'];
        }

        return array_reverse($balanceRows);
    }

    /**
     * @param array<int, string> $labels
     * @param array<int, int|float> $values
     */
    private function lineChart(string $title, array $labels, array $values, string $color, string $label): Chart
    {
        $chart = $this->chartBuilder->createChart(Chart::TYPE_LINE);
        $chart->setData([
            'labels' => $labels,
            'datasets' => [[
                'label' => $label,
                'data' => $values,
                'borderColor' => $color,
                'backgroundColor' => $this->hexToRgba($color, 0.12),
                'fill' => true,
                'tension' => 0.35,
            ]],
        ]);
        $chart->setOptions($this->cartesianOptions($title));

        return $chart;
    }

    /**
     * @param array<int, string> $labels
     * @param array<int, int|float> $values
     * @param array<int, string> $colors
     */
    private function barChart(string $title, array $labels, array $values, array $colors, string $label): Chart
    {
        $chart = $this->chartBuilder->createChart(Chart::TYPE_BAR);
        $chart->setData([
            'labels' => $labels,
            'datasets' => [[
                'label' => $label,
                'data' => $values,
                'backgroundColor' => $colors,
                'borderRadius' => 12,
                'borderSkipped' => false,
            ]],
        ]);
        $chart->setOptions($this->cartesianOptions($title));

        return $chart;
    }

    /**
     * @param array<int, string> $labels
     * @param array<int, int|float> $values
     * @param array<int, string> $colors
     */
    private function doughnutChart(string $title, array $labels, array $values, array $colors): Chart
    {
        $chart = $this->chartBuilder->createChart(Chart::TYPE_DOUGHNUT);
        $chart->setData([
            'labels' => $labels,
            'datasets' => [[
                'data' => $values,
                'backgroundColor' => $colors,
                'borderColor' => '#ffffff',
                'borderWidth' => 2,
            ]],
        ]);
        $chart->setOptions([
            'responsive' => true,
            'maintainAspectRatio' => false,
            'plugins' => [
                'legend' => ['position' => 'bottom'],
                'title' => ['display' => true, 'text' => $title],
            ],
        ]);

        return $chart;
    }

    /**
     * @return array<string, mixed>
     */
    private function cartesianOptions(string $title): array
    {
        return [
            'responsive' => true,
            'maintainAspectRatio' => false,
            'plugins' => [
                'legend' => ['position' => 'bottom'],
                'title' => ['display' => true, 'text' => $title],
            ],
            'scales' => [
                'y' => ['beginAtZero' => true, 'grid' => ['color' => 'rgba(148, 163, 184, .15)']],
                'x' => ['grid' => ['display' => false]],
            ],
        ];
    }

    private function hexToRgba(string $hex, float $alpha): string
    {
        $hex = ltrim($hex, '#');
        $r = hexdec(substr($hex, 0, 2));
        $g = hexdec(substr($hex, 2, 2));
        $b = hexdec(substr($hex, 4, 2));

        return sprintf('rgba(%d, %d, %d, %.2f)', $r, $g, $b, $alpha);
    }
}
