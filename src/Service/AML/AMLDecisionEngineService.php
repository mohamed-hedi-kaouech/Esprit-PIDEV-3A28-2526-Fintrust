<?php

namespace App\Service\AML;

use App\Entity\Wallet\Wallet;
use App\Service\AnomalyDetectionService;
use App\Service\RiskScoringService;
use App\Service\WalletAnalyticsService;

/**
 * Orchestrateur principal du module AML.
 * Point d'entrée unique pour toute analyse anti-blanchiment d'un wallet.
 */
class AMLDecisionEngineService
{
    public function __construct(
        private readonly WalletAnalyticsService  $analyticsService,
        private readonly AnomalyDetectionService $anomalyService,
        private readonly RiskScoringService      $riskService,
        private readonly AMLScoringService       $scoringService,
        private readonly AMLAlertService         $alertService,
        private readonly AMLProfileService       $profileService,
        private readonly AMLRecommendationService $recommendationService,
    ) {
    }

    /**
     * Analyse AML complète d'un wallet.
     * Accepte des données pré-calculées pour éviter les doubles appels.
     */
    public function analyzeWallet(
        Wallet $wallet,
        ?array $analytics     = null,
        ?array $anomalyReport = null,
        ?array $riskAnalysis  = null,
    ): array {
        $analytics     ??= $this->analyticsService->buildAnalytics($wallet);
        $anomalyReport ??= $this->anomalyService->detectAnomalies($wallet, $analytics);
        $riskAnalysis  ??= $this->riskService->scoreWallet($wallet, $analytics, $anomalyReport);

        $scoring        = $this->scoringService->compute($wallet, $analytics, $riskAnalysis);
        $alerts         = $this->alertService->generateAlerts($wallet, $analytics, $anomalyReport);
        $profile        = $this->profileService->classify($scoring['score'], $scoring['level'], $alerts, $wallet);
        $recommendation = $this->recommendationService->recommend($scoring['score'], $profile['code'], $alerts, $wallet);

        $criticalAlerts = array_values(array_filter($alerts, static fn($a) => $a['severity'] === 'critical'));
        $highAlerts     = array_values(array_filter($alerts, static fn($a) => $a['severity'] === 'high'));

        return [
            'score'           => $scoring['score'],
            'level'           => $scoring['level'],
            'level_label'     => $this->levelLabel($scoring['level']),
            'level_badge'     => $this->levelBadge($scoring['level']),
            'factors'         => $scoring['factors'],
            'alerts'          => $alerts,
            'alert_count'     => count($alerts),
            'critical_alerts' => $criticalAlerts,
            'high_alerts'     => $highAlerts,
            'profile'         => $profile,
            'recommendation'  => $recommendation,
            'generated_at'    => new \DateTimeImmutable(),
        ];
    }

    /**
     * Résumé AML léger pour les vues en liste.
     * Effectue une analyse complète mais ne retourne que les méta-données clés.
     */
    public function summarizeWallet(Wallet $wallet): array
    {
        $analytics     = $this->analyticsService->buildAnalytics($wallet);
        $anomalyReport = $this->anomalyService->detectAnomalies($wallet, $analytics);
        $riskAnalysis  = $this->riskService->scoreWallet($wallet, $analytics, $anomalyReport);
        $scoring       = $this->scoringService->compute($wallet, $analytics, $riskAnalysis);
        $alerts        = $this->alertService->generateAlerts($wallet, $analytics, $anomalyReport);
        $profile       = $this->profileService->classify($scoring['score'], $scoring['level'], $alerts, $wallet);

        return [
            'wallet_id'     => $wallet->getIdWallet(),
            'wallet_name'   => $wallet->getNomProprietaire(),
            'wallet_devise' => $wallet->getDevise(),
            'wallet_status' => $wallet->getStatut(),
            'is_blocked'    => (bool) $wallet->getEstBloque(),
            'score'         => $scoring['score'],
            'level'         => $scoring['level'],
            'level_label'   => $this->levelLabel($scoring['level']),
            'level_badge'   => $this->levelBadge($scoring['level']),
            'profile_code'  => $profile['code'],
            'profile_label' => $profile['label'],
            'profile_badge' => $profile['badge'],
            'alert_count'   => count($alerts),
            'critical_count'=> count(array_filter($alerts, static fn($a) => $a['severity'] === 'critical')),
            'high_count'    => count(array_filter($alerts, static fn($a) => $a['severity'] === 'high')),
        ];
    }

    private function levelLabel(string $level): string
    {
        return match ($level) {
            'critique' => 'Critique',
            'eleve'    => 'Eleve',
            'moyen'    => 'Moyen',
            default    => 'Faible',
        };
    }

    private function levelBadge(string $level): string
    {
        return match ($level) {
            'critique' => 'ft-badge-danger',
            'eleve'    => 'ft-badge-warning',
            'moyen'    => 'ft-badge-info',
            default    => 'ft-badge-success',
        };
    }
}
