<?php

namespace App\Service;

use App\Entity\User\User;
use App\Repository\KycRepository;

class UserIntelligenceService
{
    public function __construct(
        private readonly BehavioralProfileService $behavioralProfileService,
        private readonly KycRepository $kycRepository,
    ) {}

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function buildProfileEnrichment(User $user, array $context = []): array
    {
        $profile = $this->behavioralProfileService->buildProfile($user);
        $kyc = $this->kycRepository->findLatestByUser($user);

        $transactionsCount30d = (int) ($context['transactionsCount30d'] ?? round($profile['transactionFrequency'] * 30));
        $avgAmount30d = (float) ($context['avgAmount30d'] ?? $profile['averageTransactionAmount']);
        $activityChangeRate = (float) ($context['activityChangeRate'] ?? 0.08);
        $latePayments = max(0, (int) ($context['latePayments'] ?? 0));
        $deviceChanges = max(0, (int) ($context['deviceChanges'] ?? 0));
        $suspiciousFlags = max(0, (int) ($context['suspiciousFlags'] ?? 0));

        $trustScore = 100
            - ($profile['riskScore'] * 0.42)
            - ($profile['fraudScore'] * 0.18)
            - ($latePayments * 6)
            - ($deviceChanges * 5)
            - ($suspiciousFlags * 10);
        if ($user->isKycApproved()) {
            $trustScore += 8;
        }
        if ($user->getStatus() === User::STATUS_ACTIF) {
            $trustScore += 4;
        }
        $trustScore = (int) max(5, min(99, round($trustScore)));

        $financialBehaviorScore = 100
            - min(40, abs($activityChangeRate) * 100)
            - min(24, $latePayments * 6)
            - min(20, $deviceChanges * 5)
            - min(30, $suspiciousFlags * 10);
        if ($transactionsCount30d >= 15) {
            $financialBehaviorScore += 6;
        }
        if ($avgAmount30d >= 800 && $avgAmount30d <= 5000) {
            $financialBehaviorScore += 5;
        }
        $financialBehaviorScore = (int) max(10, min(98, round($financialBehaviorScore)));

        $monitoringFlags = [];
        if (!$user->isKycApproved()) {
            $monitoringFlags[] = 'KYC a finaliser';
        }
        if ($latePayments > 0) {
            $monitoringFlags[] = 'Paiement en retard observe';
        }
        if ($deviceChanges > 1) {
            $monitoringFlags[] = 'Variation d appareil a surveiller';
        }
        if ($suspiciousFlags > 0 || $user->isCriticalRisk()) {
            $monitoringFlags[] = 'Revue manuelle recommandee';
        }
        if ($transactionsCount30d === 0) {
            $monitoringFlags[] = 'Inactivite recente';
        }

        $segment = $this->resolveSegment($user, $transactionsCount30d, $financialBehaviorScore, $trustScore, $suspiciousFlags);
        $activityStatus = $transactionsCount30d === 0 ? 'INACTIVE' : ($transactionsCount30d >= 20 ? 'ACTIVE' : 'STABLE');
        $stability = $financialBehaviorScore >= 80 ? 'Stable' : ($financialBehaviorScore >= 55 ? 'A surveiller' : 'Variable');
        $lastAnalyzedAt = $user->getBehaviorUpdatedAt() ?? new \DateTimeImmutable();

        return [
            'userId' => sprintf('usr_%03d', $user->getId()),
            'segment' => $segment,
            'trustScore' => $trustScore,
            'riskScore' => round((float) $profile['riskScore'], 1),
            'financialBehaviorScore' => $financialBehaviorScore,
            'activityStatus' => $activityStatus,
            'monitoringFlags' => $monitoringFlags,
            'generatedSummary' => $this->buildSummary($user, $segment, $trustScore, $profile['riskLevel'], $activityStatus, $monitoringFlags),
            'stability' => $stability,
            'lastAnalyzedAt' => $lastAnalyzedAt->format(\DateTimeInterface::ATOM),
            'lastKycStatus' => $kyc?->getStatut(),
        ];
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function getRiskProfile(User $user, array $context = []): array
    {
        $enrichment = $this->buildProfileEnrichment($user, $context);
        $riskScore = (float) $enrichment['riskScore'];
        $riskLevel = $this->mapRiskLevel($riskScore, $user);

        $keyRiskFactors = [];
        if ($user->isKycApproved()) {
            $keyRiskFactors[] = 'KYC valide';
        } else {
            $keyRiskFactors[] = 'KYC incomplet ou en attente';
        }
        if ($user->getTransactionFrequency() >= 1) {
            $keyRiskFactors[] = 'Activite transactionnelle soutenue';
        } elseif ($user->getTransactionFrequency() == 0.0) {
            $keyRiskFactors[] = 'Activite recente faible';
        } else {
            $keyRiskFactors[] = 'Activite reguliere';
        }
        if ($user->getFraudScore() >= 55) {
            $keyRiskFactors[] = 'Pression fraude superieure a la normale';
        } else {
            $keyRiskFactors[] = 'Aucun signal fraude majeur';
        }
        if ($user->getAverageTransactionAmount() >= 2500) {
            $keyRiskFactors[] = 'Montant moyen eleve';
        }

        $alerts = [];
        if ($riskLevel === User::RISK_CRITICAL) {
            $alerts[] = 'Blocage temporaire des actions sensibles conseille';
        }
        if ($riskLevel === User::RISK_HIGH) {
            $alerts[] = 'Revue conformite recommandee';
        }
        foreach ($enrichment['monitoringFlags'] as $flag) {
            if (!in_array($flag, $alerts, true)) {
                $alerts[] = $flag;
            }
        }

        return [
            'userId' => sprintf('usr_%03d', $user->getId()),
            'globalRiskScore' => $riskScore,
            'riskLevel' => $riskLevel,
            'keyRiskFactors' => $keyRiskFactors,
            'alerts' => $alerts,
            'monitoringRecommendation' => $this->buildMonitoringRecommendation($riskLevel, $alerts),
            'updatedAt' => $enrichment['lastAnalyzedAt'],
            'history' => $this->buildMonitoringTimeline($user),
        ];
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function getFinancialBehaviorSummary(User $user, array $context = []): array
    {
        $enrichment = $this->buildProfileEnrichment($user, $context);
        $frequency = (float) $user->getTransactionFrequency();
        $avg = (float) $user->getAverageTransactionAmount();

        $trend = $frequency >= 1.0 ? 'Croissance reguliere' : ($frequency >= 0.35 ? 'Stable' : 'Ralentie');
        $activityRecent = $frequency >= 1.0 ? 'Forte' : ($frequency >= 0.35 ? 'Moderee' : 'Faible');
        $stabilityScore = (int) max(10, min(98, round((float) $enrichment['financialBehaviorScore'])));

        $anomalyFlags = [];
        if ($user->getFraudScore() >= 60) {
            $anomalyFlags[] = 'Pression fraude elevee';
        }
        if ($user->getRiskLevel() === User::RISK_CRITICAL) {
            $anomalyFlags[] = 'Profil critique';
        }
        if ($frequency === 0.0) {
            $anomalyFlags[] = 'Aucune operation recente';
        }

        return [
            'userId' => sprintf('usr_%03d', $user->getId()),
            'avgTransactionAmount' => round($avg, 2),
            'transactionFrequency' => round($frequency, 2),
            'activityTrend' => $trend,
            'recentActivity' => $activityRecent,
            'stabilityScore' => $stabilityScore,
            'anomalyFlags' => $anomalyFlags,
            'summary' => $this->buildBehaviorSummary($frequency, $avg, $trend, $anomalyFlags),
            'updatedAt' => $enrichment['lastAnalyzedAt'],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function buildMonitoringTimeline(User $user): array
    {
        $timeline = [
            [
                'label' => 'Creation du compte',
                'detail' => 'Ouverture du profil FinTrust et initialisation des acces.',
                'date' => $user->getCreatedAt(),
                'tone' => 'info',
            ],
        ];

        if ($user->getBehaviorUpdatedAt()) {
            $timeline[] = [
                'label' => 'Analyse comportementale',
                'detail' => sprintf(
                    'Segment %s, risque %s et score %.1f/100 mis a jour.',
                    $user->getClientSegment(),
                    $user->getRiskLevel(),
                    $user->getRiskScore()
                ),
                'date' => $user->getBehaviorUpdatedAt(),
                'tone' => $user->isAtRisk() ? 'warning' : 'success',
            ];
        }

        $kyc = $this->kycRepository->findLatestByUser($user);
        if ($kyc) {
            $timeline[] = [
                'label' => 'Dossier KYC',
                'detail' => sprintf('Dernier dossier %s avec %d justificatif(s).', strtolower($kyc->getStatut()), $kyc->getFiles()->count()),
                'date' => $kyc->getDateSubmission(),
                'tone' => $kyc->getStatut() === 'APPROUVE' ? 'success' : ($kyc->getStatut() === 'REFUSE' ? 'danger' : 'warning'),
            ];
        }

        usort($timeline, static fn(array $left, array $right) => $right['date'] <=> $left['date']);

        return array_map(static function (array $item): array {
            $item['date'] = $item['date']->format('d/m/Y H:i');

            return $item;
        }, $timeline);
    }

    private function resolveSegment(User $user, int $transactionsCount30d, int $financialBehaviorScore, int $trustScore, int $suspiciousFlags): string
    {
        if ($suspiciousFlags >= 2 || $user->isCriticalRisk()) {
            return 'POTENTIALLY_FRAUDULENT';
        }

        if ($transactionsCount30d === 0) {
            return 'INACTIVE';
        }

        if ($user->isAtRisk() || $trustScore < 55) {
            return 'AT_RISK';
        }

        if ($transactionsCount30d >= 20 && $financialBehaviorScore >= 80 && $trustScore >= 78) {
            return 'VIP';
        }

        return 'STANDARD';
    }

    private function mapRiskLevel(float $riskScore, User $user): string
    {
        if ($user->isCriticalRisk() || $riskScore >= 80) {
            return 'CRITICAL';
        }
        if ($riskScore >= 60) {
            return 'HIGH';
        }
        if ($riskScore >= 30) {
            return 'MEDIUM';
        }

        return 'LOW';
    }

    /**
     * @param list<string> $monitoringFlags
     */
    private function buildSummary(User $user, string $segment, int $trustScore, string $riskLevel, string $activityStatus, array $monitoringFlags): string
    {
        $base = match ($segment) {
            'VIP' => 'Client a forte valeur avec activite coherente et niveau de confiance eleve.',
            'AT_RISK' => 'Client a surveiller avec quelques signaux de vigilance a traiter.',
            'INACTIVE' => 'Client faiblement actif necessitant une relance douce.',
            'POTENTIALLY_FRAUDULENT' => 'Profil sensible avec combinaison de signaux critiques a escalader.',
            default => 'Client stable au comportement globalement regulier.',
        };

        $extra = sprintf(' Trust score %d/100, risque %s et activite %s.', $trustScore, strtolower($riskLevel), strtolower($activityStatus));

        if ($monitoringFlags === []) {
            return $base . $extra . ' Aucun signal majeur detecte.';
        }

        return $base . $extra . ' Vigilance: ' . implode(', ', array_slice($monitoringFlags, 0, 2)) . '.';
    }

    /**
     * @param list<string> $alerts
     */
    private function buildMonitoringRecommendation(string $riskLevel, array $alerts): string
    {
        if ($riskLevel === 'CRITICAL') {
            return 'Escalade immediate vers la revue manuelle et restriction des modules sensibles.';
        }
        if ($riskLevel === 'HIGH') {
            return 'Surveillance renforcee avec controle conformite sous 24 heures.';
        }
        if ($alerts !== []) {
            return 'Surveillance standard avec verification ponctuelle des signaux faibles.';
        }

        return 'Surveillance standard.';
    }

    /**
     * @param list<string> $anomalyFlags
     */
    private function buildBehaviorSummary(float $frequency, float $avg, string $trend, array $anomalyFlags): string
    {
        $summary = sprintf(
            'Frequence %.2f operation(s) par jour, montant moyen %.2f TND et tendance %s.',
            $frequency,
            $avg,
            strtolower($trend)
        );

        if ($anomalyFlags === []) {
            return $summary . ' Le comportement reste coherent avec un profil bancaire sain.';
        }

        return $summary . ' Signaux a surveiller: ' . implode(', ', $anomalyFlags) . '.';
    }
}
