<?php

namespace App\Service\Loan;

use App\Entity\Loan\Loan;

/**
 * Scores the risk of a loan on a 0–100 scale.
 *
 * Score bands:
 *   0–39  → LOW    (green)
 *  40–69  → MEDIUM (amber)
 *  70–100 → HIGH   (red)
 *
 * Four independent factors, each contributing a weighted penalty:
 *
 *  1. Amount-to-ceiling ratio   (40 pts max)
 *     How close the requested amount is to the type ceiling.
 *     Larger loan = harder to repay = more risk.
 *
 *  2. Duration                  (20 pts max)
 *     Longer term = more time for default to occur.
 *     Short loans (≤12 m) are low risk; 36 m is max risk for this model.
 *
 *  3. Monthly-payment burden    (25 pts max)
 *     Monthly payment expressed as a multiple of a reference salary
 *     (1 500 TND – median Tunisian private-sector wage).
 *     Payment > 50 % of salary → maximum penalty.
 *
 *  4. Interest-cost ratio       (15 pts max)
 *     Total interest / principal.  High ratio signals an expensive loan.
 */
class LoanRiskService
{
    private const TYPE_CEILINGS = [
        'PERSONNEL' => 25_000,
        'VOITURE'   => 50_000,
        'LOGEMENT'  => 200_000,
    ];

    private const REFERENCE_SALARY = 1_500.0;   // TND / month

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Returns a full risk assessment array:
     *
     * [
     *   'score'         => int   (0–100),
     *   'level'         => string ('LOW' | 'MEDIUM' | 'HIGH'),
     *   'label'         => string (human-readable French label),
     *   'color'         => string (Tailwind color name: 'green' | 'amber' | 'red'),
     *   'factors'       => array  (breakdown per factor),
     *   'recommendation'=> string,
     * ]
     */
    public function assess(Loan $loan): array
    {
        $factors = [
            $this->scoreAmountRatio($loan),
            $this->scoreDuration($loan),
            $this->scorePaymentBurden($loan),
            $this->scoreInterestRatio($loan),
        ];

        $score = (int) round(array_sum(array_column($factors, 'points')));
        $score = max(0, min(100, $score));

        [$level, $label, $color] = $this->band($score);

        return [
            'score'          => $score,
            'level'          => $level,
            'label'          => $label,
            'color'          => $color,
            'factors'        => $factors,
            'recommendation' => $this->recommendation($level, $loan),
        ];
    }

    /** Convenience: just the 0–100 integer score. */
    public function score(Loan $loan): int
    {
        return $this->assess($loan)['score'];
    }

    /** Convenience: 'LOW' | 'MEDIUM' | 'HIGH' */
    public function level(Loan $loan): string
    {
        return $this->assess($loan)['level'];
    }

    // -------------------------------------------------------------------------
    // Individual factor scorers
    // -------------------------------------------------------------------------

    /** Factor 1 – Amount vs type ceiling (max 40 pts) */
    private function scoreAmountRatio(Loan $loan): array
    {
        $ceiling = self::TYPE_CEILINGS[$loan->getLoanType()] ?? 25_000;
        $ratio   = min(1.0, (float) $loan->getAmount() / $ceiling);
        $points  = round($ratio * 40, 2);

        return [
            'name'    => 'Montant / plafond',
            'points'  => $points,
            'max'     => 40,
            'detail'  => sprintf(
                '%.0f TND sur %s TND (%.0f %%)',
                $loan->getAmount(),
                number_format($ceiling, 0, ',', ' '),
                $ratio * 100
            ),
        ];
    }

    /** Factor 2 – Duration (max 20 pts) */
    private function scoreDuration(Loan $loan): array
    {
        // Linear: 6 months → 0 pts, 36 months → 20 pts
        $duration = max(6, min(36, $loan->getDuration()));
        $points   = round((($duration - 6) / 30) * 20, 2);

        return [
            'name'   => 'Durée',
            'points' => $points,
            'max'    => 20,
            'detail' => $loan->getDuration() . ' mois',
        ];
    }

    /** Factor 3 – Monthly payment burden vs reference salary (max 25 pts) */
    private function scorePaymentBurden(Loan $loan): array
    {
        $monthly = $this->monthlyPayment($loan);
        // Ratio of payment to salary, capped at 1 (= 100 % of salary)
        $ratio   = min(1.0, $monthly / self::REFERENCE_SALARY);
        $points  = round($ratio * 25, 2);

        return [
            'name'   => 'Charge mensuelle',
            'points' => $points,
            'max'    => 25,
            'detail' => sprintf(
                '%.3f TND/mois (%.0f %% du salaire de référence)',
                $monthly,
                $ratio * 100
            ),
        ];
    }

    /** Factor 4 – Total interest / principal ratio (max 15 pts) */
    private function scoreInterestRatio(Loan $loan): array
    {
        $principal = (float) $loan->getAmount();
        if ($principal <= 0) {
            return ['name' => 'Coût des intérêts', 'points' => 0, 'max' => 15, 'detail' => 'N/A'];
        }

        $totalInterest = $this->totalInterest($loan);
        // Ratio capped at 50 % (a loan that costs more than half its value in interest)
        $ratio  = min(1.0, ($totalInterest / $principal) / 0.5);
        $points = round($ratio * 15, 2);

        return [
            'name'   => 'Coût des intérêts',
            'points' => $points,
            'max'    => 15,
            'detail' => sprintf(
                '%.3f TND (%.1f %% du capital)',
                $totalInterest,
                ($totalInterest / $principal) * 100
            ),
        ];
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function monthlyPayment(Loan $loan): float
    {
        $r = (float) $loan->getInterestRate() / 100 / 12;
        $p = (float) $loan->getAmount();
        $n = $loan->getDuration();

        if ($r == 0) {
            return $p / $n;
        }

        return $p * ($r * pow(1 + $r, $n)) / (pow(1 + $r, $n) - 1);
    }

    private function totalInterest(Loan $loan): float
    {
        return $this->monthlyPayment($loan) * $loan->getDuration() - (float) $loan->getAmount();
    }

    /** @return array{string, string, string} [level, label, color] */
    private function band(int $score): array
    {
        if ($score < 40) {
            return ['LOW',    'Risque faible',  'green'];
        }
        if ($score < 70) {
            return ['MEDIUM', 'Risque modéré',  'amber'];
        }
        return ['HIGH',   'Risque élevé',  'red'];
    }

    private function recommendation(string $level, Loan $loan): string
    {
        return match ($level) {
            'LOW'    => 'Ce prêt présente un profil de risque acceptable. Approbation recommandée.',
            'MEDIUM' => 'Ce prêt présente un risque modéré. Vérifiez les garanties et la solvabilité avant approbation.',
            'HIGH'   => sprintf(
                'Ce prêt est à haut risque (montant élevé, durée longue ou charge mensuelle importante). Considérez une réduction du montant ou un refus.',
            ),
            default  => '',
        };
    }
}