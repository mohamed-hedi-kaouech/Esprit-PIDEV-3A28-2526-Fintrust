<?php

namespace App\Service;

use App\Entity\Wallet\Cheque;
use App\Entity\Wallet\Transaction;
use App\Entity\Wallet\Wallet;
use Doctrine\ORM\EntityManagerInterface;

class WalletAssistantService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly WalletAnalyticsService $walletAnalyticsService,
        private readonly AnomalyDetectionService $anomalyDetectionService,
        private readonly RiskScoringService $riskScoringService,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function answer(Wallet $wallet, string $intent): array
    {
        $analytics = $this->walletAnalyticsService->buildAnalytics($wallet);
        $anomalyReport = $this->anomalyDetectionService->detectAnomalies($wallet, $analytics);
        $risk = $this->riskScoringService->scoreWallet($wallet, $analytics, $anomalyReport);
        $loanAdvice = $this->buildLoanAdvice($wallet, $analytics, $risk);

        return match ($intent) {
            'balance' => [
                'title' => 'Solde disponible',
                'message' => sprintf('Votre solde actuel est de %.2f %s.', (float) $wallet->getSolde(), $wallet->getDevise()),
                'details' => ['Statut: ' . $wallet->getStatut(), 'Wallet #' . $wallet->getIdWallet()],
                'loanAdvice' => $loanAdvice,
            ],
            'transactions' => $this->transactionsAnswer($wallet, $loanAdvice),
            'status' => [
                'title' => 'Etat du wallet',
                'message' => $wallet->getEstBloque()
                    ? 'Votre wallet est bloque. Les operations sensibles sont limitees.'
                    : 'Votre wallet est operationnel pour les actions autorisees.',
                'details' => [
                    'Statut: ' . $wallet->getStatut(),
                    'Actif: ' . ($wallet->getEstActif() ? 'oui' : 'non'),
                    'Tentatives echouees: ' . (int) ($wallet->getTentativesEchouees() ?? 0),
                ],
                'loanAdvice' => $loanAdvice,
            ],
            'profile' => [
                'title' => 'Analyse du profil',
                'message' => sprintf('Votre score de risque est %d/100 (%s). %s', (int) $risk['score'], (string) $risk['level'], (string) $risk['explanation']),
                'details' => array_map(
                    static fn (array $factor): string => (string) ($factor['label'] ?? 'Signal') . ' - ' . (string) ($factor['reason'] ?? ''),
                    array_slice($risk['factors'] ?? [], 0, 4)
                ),
                'loanAdvice' => $loanAdvice,
            ],
            default => [
                'title' => 'Conseil pret',
                'message' => $loanAdvice['explanation'],
                'details' => $loanAdvice['signals'],
                'loanAdvice' => $loanAdvice,
            ],
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function buildLoanAdvice(Wallet $wallet, array $analytics, array $risk): array
    {
        $metrics = is_array($analytics['metrics'] ?? null) ? $analytics['metrics'] : [];
        $score = 50;
        $signals = [];

        $balance = (float) $wallet->getSolde();
        if ($balance >= 5000) {
            $score += 18;
            $signals[] = 'Solde actuel confortable.';
        } elseif ($balance >= 1000) {
            $score += 8;
            $signals[] = 'Solde actuel correct.';
        } else {
            $score -= 14;
            $signals[] = 'Solde actuel faible pour soutenir une demande.';
        }

        $averageTransactionsPerDay = (float) ($metrics['average_transactions_per_day'] ?? 0.0);
        if ($averageTransactionsPerDay >= 0.2 && $averageTransactionsPerDay <= 4.0) {
            $score += 10;
            $signals[] = 'Frequence transactionnelle reguliere.';
        } elseif ($averageTransactionsPerDay > 6.0) {
            $score -= 8;
            $signals[] = 'Activite tres intense, a expliquer au conseiller.';
        }

        $stability = (float) ($metrics['balance_instability_index'] ?? 0.0);
        if ($stability <= 0.7) {
            $score += 12;
            $signals[] = 'Solde relativement stable.';
        } elseif ($stability >= 1.5) {
            $score -= 12;
            $signals[] = 'Solde volatil.';
        }

        $riskScore = (int) ($risk['score'] ?? 0);
        $score -= (int) round($riskScore * 0.35);

        $refusedCheques = (int) ($metrics['refused_cheques'] ?? 0);
        if ($refusedCheques > 0) {
            $score -= min(20, $refusedCheques * 8);
            $signals[] = sprintf('%d cheque(s) refuse(s) impactent le profil.', $refusedCheques);
        }

        $score = max(0, min(100, $score));
        $decision = match (true) {
            $score >= 70 => 'favorable',
            $score >= 45 => 'moyen',
            default => 'faible',
        };

        $recommendation = match ($decision) {
            'favorable' => 'Profil interessant: preparez justificatifs de revenus, objet du pret et montant souhaite.',
            'moyen' => 'Profil a consolider: stabilisez le solde et limitez les sorties importantes avant la demande.',
            default => 'Profil fragile: reduisez les incidents et renforcez le solde avant de solliciter un pret.',
        };

        return [
            'score' => $score,
            'decision' => $decision,
            'explanation' => sprintf('Avis pret: %s (%d/100). %s', $decision, $score, $recommendation),
            'recommendation' => $recommendation,
            'signals' => $signals,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function transactionsAnswer(Wallet $wallet, array $loanAdvice): array
    {
        /** @var Transaction[] $transactions */
        $transactions = $this->entityManager->getRepository(Transaction::class)
            ->createQueryBuilder('t')
            ->andWhere('t.idWallet = :walletId')
            ->setParameter('walletId', $wallet->getIdWallet())
            ->orderBy('t.dateTransaction', 'DESC')
            ->setMaxResults(5)
            ->getQuery()
            ->getResult();

        return [
            'title' => 'Dernieres transactions',
            'message' => count($transactions) > 0
                ? 'Voici vos operations les plus recentes.'
                : 'Aucune transaction recente n est disponible.',
            'details' => array_map(
                static fn (Transaction $transaction): string => sprintf(
                    '#%d %s %.2f le %s',
                    $transaction->getIdTransaction(),
                    $transaction->getType(),
                    $transaction->getMontant(),
                    $transaction->getDateTransaction()->format('d/m/Y H:i')
                ),
                $transactions
            ),
            'loanAdvice' => $loanAdvice,
        ];
    }
}
