<?php

namespace App\Service;

use App\Entity\User\User;
use App\Entity\Wallet\Transaction;
use App\Entity\Wallet\Wallet;
use Doctrine\ORM\EntityManagerInterface;

class BehavioralProfileService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}

    public function refreshUserBehavior(User $user, bool $flush = true): void
    {
        $profile = $this->buildProfile($user);

        $user
            ->setTransactionFrequency($profile['transactionFrequency'])
            ->setAverageTransactionAmount($profile['averageTransactionAmount'])
            ->setFraudScore($profile['fraudScore'])
            ->setRiskScore($profile['riskScore'])
            ->setRiskLevel($profile['riskLevel'])
            ->setClientSegment($profile['clientSegment'])
            ->setBehaviorUpdatedAt(new \DateTime());

        if ($flush) {
            $this->em->flush();
        }
    }

    /**
     * @return array{
     *   transactionFrequency: float,
     *   averageTransactionAmount: float,
     *   fraudScore: float,
     *   riskScore: float,
     *   riskLevel: string,
     *   clientSegment: string
     * }
     */
    public function buildProfile(User $user): array
    {
        $wallets = $this->em->getRepository(Wallet::class)->findBy(['user' => $user]);

        $transactions = [];
        foreach ($wallets as $wallet) {
            foreach ($wallet->getTransactions() as $transaction) {
                if ($transaction instanceof Transaction) {
                    $transactions[] = $transaction;
                }
            }
        }

        $count = count($transactions);
        $totalAmount = 0.0;
        $recentCount = 0;
        $highAmountCount = 0;
        $nightTransactionCount = 0;
        $now = new \DateTimeImmutable();

        foreach ($transactions as $transaction) {
            $amount = abs($transaction->getMontant());
            $totalAmount += $amount;

            $date = $transaction->getDateTransaction();
            if ($date >= $now->modify('-30 days')) {
                $recentCount++;
            }

            if ($amount >= 5000) {
                $highAmountCount++;
            }

            $hour = (int) $date->format('G');
            if ($hour <= 5) {
                $nightTransactionCount++;
            }
        }

        $averageAmount = $count > 0 ? round($totalAmount / $count, 2) : 0.0;
        $frequency = round($recentCount / 30, 2);

        $behaviorPressure = 0;
        if (!$user->isKycApproved()) {
            $behaviorPressure += 2;
        }
        if ($user->getStatus() === User::STATUS_SUSPENDU) {
            $behaviorPressure += 3;
        } elseif ($user->getStatus() === User::STATUS_EN_ATTENTE) {
            $behaviorPressure += 1;
        }
        if ($frequency >= 1.5) {
            $behaviorPressure += 2;
        } elseif ($frequency >= 0.75) {
            $behaviorPressure += 1;
        }
        if ($averageAmount >= 5000) {
            $behaviorPressure += 2;
        } elseif ($averageAmount >= 1500) {
            $behaviorPressure += 1;
        }
        if ($nightTransactionCount >= 3) {
            $behaviorPressure += 2;
        } elseif ($nightTransactionCount >= 1) {
            $behaviorPressure += 1;
        }
        if ($highAmountCount >= 4) {
            $behaviorPressure += 2;
        } elseif ($highAmountCount >= 1) {
            $behaviorPressure += 1;
        }

        $fraudScore = 5.0;
        if ($behaviorPressure >= 7) {
            $fraudScore += 24;
        } elseif ($behaviorPressure >= 4) {
            $fraudScore += 12;
        }
        if ($nightTransactionCount >= 3) {
            $fraudScore += 16;
        } elseif ($nightTransactionCount >= 1) {
            $fraudScore += 7;
        }
        if ($highAmountCount >= 4) {
            $fraudScore += 18;
        } elseif ($highAmountCount >= 1) {
            $fraudScore += 8;
        }
        if (!$user->isKycApproved()) {
            $fraudScore += 10;
        }
        if ($user->getStatus() === User::STATUS_SUSPENDU) {
            $fraudScore += 14;
        } elseif ($user->getStatus() === User::STATUS_EN_ATTENTE) {
            $fraudScore += 5;
        }

        $fraudScore = max(0.0, min(100.0, round($fraudScore, 2)));

        $riskScore = 8.0 + ($fraudScore * 0.55);

        if (!$user->isKycApproved()) {
            $riskScore += 18;
        }
        if ($user->getStatus() !== User::STATUS_ACTIF) {
            $riskScore += 12;
        }
        if ($frequency >= 1.5) {
            $riskScore += 12;
        } elseif ($frequency >= 0.5) {
            $riskScore += 6;
        }
        if ($averageAmount >= 5000) {
            $riskScore += 28;
        } elseif ($averageAmount >= 1500) {
            $riskScore += 14;
        } elseif ($averageAmount >= 500) {
            $riskScore += 6;
        }
        if ($count === 0) {
            $riskScore = max(5.0, $riskScore - 6);
        }
        if ($nightTransactionCount >= 3) {
            $riskScore += 10;
        } elseif ($nightTransactionCount >= 1) {
            $riskScore += 4;
        }
        if ($behaviorPressure >= 7) {
            $riskScore += 18;
        } elseif ($behaviorPressure >= 4) {
            $riskScore += 8;
        }

        $riskScore = max(0.0, min(100.0, round($riskScore, 2)));

        $riskLevel = match (true) {
            $riskScore >= 85 || $fraudScore >= 70 => User::RISK_CRITICAL,
            $riskScore >= 60 => User::RISK_HIGH,
            $riskScore >= 30 => User::RISK_MEDIUM,
            default => User::RISK_LOW,
        };

        $clientSegment = User::SEGMENT_STANDARD;
        if (in_array($riskLevel, [User::RISK_HIGH, User::RISK_CRITICAL], true) || $user->getStatus() === User::STATUS_SUSPENDU) {
            $clientSegment = User::SEGMENT_AT_RISK;
        } elseif ($user->isKycApproved() && $averageAmount >= 2500 && $count >= 8) {
            $clientSegment = User::SEGMENT_VIP;
        }

        return [
            'transactionFrequency' => $frequency,
            'averageTransactionAmount' => $averageAmount,
            'fraudScore' => $fraudScore,
            'riskScore' => $riskScore,
            'riskLevel' => $riskLevel,
            'clientSegment' => $clientSegment,
        ];
    }
}
