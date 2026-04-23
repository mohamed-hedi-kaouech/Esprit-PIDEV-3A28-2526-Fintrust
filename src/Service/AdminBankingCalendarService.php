<?php

namespace App\Service;

use App\Entity\Wallet\Cheque;
use App\Entity\Wallet\Transaction;
use App\Entity\Wallet\Wallet;
use Doctrine\ORM\EntityManagerInterface;

class AdminBankingCalendarService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function buildEvents(\DateTimeImmutable $start, \DateTimeImmutable $end): array
    {
        $days = $this->buildDailyActivity($start, $end);
        $events = [];

        foreach ($days as $date => $day) {
            $color = $this->resolveEventColor((string) $day['risk_level']);
            $events[] = [
                'id' => 'banking-' . $date,
                'title' => $this->formatEventTitle($day),
                'start' => $date,
                'allDay' => true,
                'backgroundColor' => $color,
                'borderColor' => $color,
                'extendedProps' => $day,
            ];
        }

        return $events;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function buildDailyActivity(\DateTimeImmutable $start, \DateTimeImmutable $end): array
    {
        $days = [];
        for ($cursor = $start; $cursor < $end; $cursor = $cursor->modify('+1 day')) {
            $days[$cursor->format('Y-m-d')] = $this->emptyDay($cursor);
        }

        /** @var Transaction[] $transactions */
        $transactions = $this->entityManager->getRepository(Transaction::class)
            ->createQueryBuilder('t')
            ->andWhere('t.dateTransaction >= :start')
            ->andWhere('t.dateTransaction < :end')
            ->setParameter('start', $start)
            ->setParameter('end', $end)
            ->orderBy('t.dateTransaction', 'ASC')
            ->getQuery()
            ->getResult();

        foreach ($transactions as $transaction) {
            $date = $transaction->getDateTransaction()->format('Y-m-d');
            if (!isset($days[$date])) {
                continue;
            }

            $type = $this->normalizeType($transaction->getType());
            $amount = round((float) $transaction->getMontant(), 2);
            $wallet = $transaction->getWallet();
            $walletId = $wallet->getIdWallet();
            $days[$date]['transaction_count']++;
            $days[$date]['total_volume'] += $amount;
            $days[$date]['type_counts'][$type] = ($days[$date]['type_counts'][$type] ?? 0) + 1;
            $days[$date]['type_amounts'][$type] = ($days[$date]['type_amounts'][$type] ?? 0.0) + $amount;
            $typeCountKey = $this->resolveTypeCountKey($type);
            if ($typeCountKey !== null) {
                $days[$date][$typeCountKey]++;
                $days[$date][$this->resolveTypeAmountKey($typeCountKey)] += $amount;
            }
            $days[$date]['wallet_activity'][$walletId] ??= [
                'id' => $walletId,
                'label' => $this->formatWalletLabel($wallet),
                'transaction_count' => 0,
                'total_volume' => 0.0,
            ];
            $days[$date]['wallet_activity'][$walletId]['transaction_count']++;
            $days[$date]['wallet_activity'][$walletId]['total_volume'] += $amount;

            if ($this->isAnomalousTransaction($transaction)) {
                $days[$date]['anomalies']++;
                $days[$date]['anomaly_notes'][] = sprintf(
                    'Transaction #%d: %s %.2f',
                    $transaction->getIdTransaction(),
                    $type,
                    $amount
                );
            }
        }

        /** @var Cheque[] $cheques */
        $cheques = $this->entityManager->getRepository(Cheque::class)
            ->createQueryBuilder('c')
            ->andWhere('c.dateEmission >= :start')
            ->andWhere('c.dateEmission < :end')
            ->setParameter('start', $start)
            ->setParameter('end', $end)
            ->orderBy('c.dateEmission', 'ASC')
            ->getQuery()
            ->getResult();

        foreach ($cheques as $cheque) {
            $date = $cheque->getDateEmission()->format('Y-m-d');
            if (!isset($days[$date])) {
                continue;
            }

            $days[$date]['cheque_requests']++;
            if (mb_strtolower($cheque->getStatut()) === 'refuse') {
                $days[$date]['cheque_refusals']++;
            }
        }

        /** @var Wallet[] $wallets */
        $wallets = $this->entityManager->getRepository(Wallet::class)
            ->createQueryBuilder('w')
            ->andWhere('w.estBloque = :blocked')
            ->setParameter('blocked', true)
            ->getQuery()
            ->getResult();

        foreach ($wallets as $wallet) {
            foreach ($days as &$day) {
                $day['wallets_blocked']++;
            }
            unset($day);
        }

        foreach ($days as &$day) {
            $day['total_volume'] = round((float) $day['total_volume'], 2);
            $day['deposit_total'] = round((float) $day['deposit_total'], 2);
            $day['withdrawal_total'] = round((float) $day['withdrawal_total'], 2);
            $day['transfer_total'] = round((float) $day['transfer_total'], 2);
            $day['dominant_type'] = $this->findDominantType($day['type_counts']);
            $day['top_wallets'] = $this->buildTopWallets($day['wallet_activity']);
            unset($day['wallet_activity']);
        }
        unset($day);

        $this->flagActivityPeaks($days);

        return $days;
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyDay(\DateTimeImmutable $date): array
    {
        return [
            'date' => $date->format('Y-m-d'),
            'label' => $date->format('d/m/Y'),
            'transaction_count' => 0,
            'total_volume' => 0.0,
            'dominant_type' => 'aucun',
            'anomalies' => 0,
            'anomaly_notes' => [],
            'wallets_blocked' => 0,
            'cheque_requests' => 0,
            'cheque_refusals' => 0,
            'deposit_count' => 0,
            'deposit_total' => 0.0,
            'withdrawal_count' => 0,
            'withdrawal_total' => 0.0,
            'transfer_count' => 0,
            'transfer_total' => 0.0,
            'type_counts' => [],
            'type_amounts' => [],
            'top_wallets' => [],
            'wallet_activity' => [],
            'is_peak_activity' => false,
            'risk_level' => 'normal',
            'risk_label' => 'Activite normale',
        ];
    }

    private function isAnomalousTransaction(Transaction $transaction): bool
    {
        $hour = (int) $transaction->getDateTransaction()->format('G');

        return (float) $transaction->getMontant() >= 5000.0 || $hour >= 22 || $hour < 6;
    }

    /**
     * @param array<string, int> $typeCounts
     */
    private function findDominantType(array $typeCounts): string
    {
        if ($typeCounts === []) {
            return 'aucun';
        }

        arsort($typeCounts);

        return (string) array_key_first($typeCounts);
    }

    private function normalizeType(string $type): string
    {
        $type = mb_strtolower(trim($type));

        return $type !== '' ? $type : 'autre';
    }

    /**
     * @param array<string, mixed> $day
     */
    private function formatEventTitle(array $day): string
    {
        $transactionCount = (int) $day['transaction_count'];
        $transactionLabel = $transactionCount > 1 ? 'transactions' : 'transaction';
        $amount = number_format((float) $day['total_volume'], 0, ',', ' ');

        return sprintf(
            '%d %s | %s TND | %s',
            $transactionCount,
            $transactionLabel,
            $amount,
            $this->formatDominantType((string) $day['dominant_type'])
        );
    }

    private function formatDominantType(string $type): string
    {
        return match ($this->normalizeType($type)) {
            'depot', 'deposit', 'd' => 'Dépôt',
            'retrait', 'withdrawal', 'withdraw', 'r' => 'Retrait',
            'transfert', 'transfer', 'tr' => 'Transfert',
            'aucun', 'auc', 'none' => 'Aucune activité',
            default => ucfirst($type),
        };
    }

    private function resolveTypeCountKey(string $type): ?string
    {
        if (str_contains($type, 'depot') || str_contains($type, 'deposit')) {
            return 'deposit_count';
        }

        if (str_contains($type, 'retrait') || str_contains($type, 'withdraw')) {
            return 'withdrawal_count';
        }

        if (str_contains($type, 'transfert') || str_contains($type, 'transfer')) {
            return 'transfer_count';
        }

        return null;
    }

    private function resolveTypeAmountKey(string $typeCountKey): string
    {
        return str_replace('_count', '_total', $typeCountKey);
    }

    private function formatWalletLabel(Wallet $wallet): string
    {
        $owner = trim((string) $wallet->getNomProprietaire());
        if ($owner !== '') {
            return sprintf('#%d - %s', $wallet->getIdWallet(), $owner);
        }

        return sprintf('Wallet #%d', $wallet->getIdWallet());
    }

    /**
     * @param array<int, array{id:int,label:string,transaction_count:int,total_volume:float}> $walletActivity
     * @return array<int, array{id:int,label:string,transaction_count:int,total_volume:float}>
     */
    private function buildTopWallets(array $walletActivity): array
    {
        uasort($walletActivity, static function (array $left, array $right): int {
            return $right['transaction_count'] <=> $left['transaction_count']
                ?: $right['total_volume'] <=> $left['total_volume'];
        });

        return array_map(
            static fn (array $wallet): array => [
                'id' => (int) $wallet['id'],
                'label' => (string) $wallet['label'],
                'transaction_count' => (int) $wallet['transaction_count'],
                'total_volume' => round((float) $wallet['total_volume'], 2),
            ],
            array_slice(array_values($walletActivity), 0, 5)
        );
    }

    /**
     * @param array<string, array<string, mixed>> $days
     */
    private function flagActivityPeaks(array &$days): void
    {
        $activeDays = array_filter($days, static fn (array $day): bool => (int) $day['transaction_count'] > 0);
        $averageTransactions = $activeDays !== []
            ? array_sum(array_map(static fn (array $day): int => (int) $day['transaction_count'], $activeDays)) / count($activeDays)
            : 0.0;
        $averageVolume = $activeDays !== []
            ? array_sum(array_map(static fn (array $day): float => (float) $day['total_volume'], $activeDays)) / count($activeDays)
            : 0.0;

        foreach ($days as &$day) {
            $day['is_peak_activity'] = (int) $day['transaction_count'] >= max(5, (int) ceil($averageTransactions * 1.8))
                || (float) $day['total_volume'] >= max(10000.0, $averageVolume * 2);
            [$day['risk_level'], $day['risk_label']] = $this->resolveRiskLevel($day);
        }
        unset($day);
    }

    /**
     * @param array<string, mixed> $day
     * @return array{0:string,1:string}
     */
    private function resolveRiskLevel(array $day): array
    {
        if ((int) $day['cheque_refusals'] > 0 || (int) $day['anomalies'] >= 3) {
            return ['high', 'Risque eleve'];
        }

        if ((int) $day['anomalies'] > 0 || (int) $day['wallets_blocked'] > 0) {
            return ['anomaly', 'Anomalie a verifier'];
        }

        if ((bool) $day['is_peak_activity']) {
            return ['elevated', 'Pic d activite'];
        }

        return ['normal', 'Activite normale'];
    }

    private function resolveEventColor(string $riskLevel): string
    {
        return match ($riskLevel) {
            'high' => '#dc2626',
            'anomaly' => '#f97316',
            'elevated' => '#eab308',
            default => '#16a34a',
        };
    }
}
