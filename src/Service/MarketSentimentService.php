<?php

namespace App\Service;

use App\Entity\Wallet\Wallet;

class MarketSentimentService
{
    public function __construct(
        private readonly WalletAnalyticsService $walletAnalyticsService,
    ) {
    }

    /**
     * @param array<string, mixed> $marketData
     * @return array<string, mixed>
     */
    public function buildSummary(array $marketData, ?Wallet $wallet = null): array
    {
        $assets = is_array($marketData['assets'] ?? null) ? $marketData['assets'] : [];
        $walletContext = is_array($marketData['wallet_context'] ?? null) ? $marketData['wallet_context'] : null;

        $upCount = 0;
        $downCount = 0;
        $highVolatilityCount = 0;

        foreach ($assets as $asset) {
            $trend = (string) ($asset['trend'] ?? 'STABLE');
            $volatility = (float) ($asset['volatility'] ?? 0.0);

            if ($trend === 'UP') {
                $upCount++;
            } elseif ($trend === 'DOWN') {
                $downCount++;
            }

            if ($volatility >= 20.0) {
                $highVolatilityCount++;
            }
        }

        $marketSentiment = match (true) {
            $upCount >= 3 && $highVolatilityCount <= 1 => 'constructive',
            $downCount >= 2 && $highVolatilityCount >= 2 => 'defensive',
            $highVolatilityCount >= 2 => 'watchful',
            default => 'balanced',
        };

        return [
            'market_sentiment' => $marketSentiment,
            'risk_factors' => $this->buildRiskFactors($assets, $wallet),
            'recommended_actions' => $this->buildRecommendedActions($assets, $marketSentiment, $walletContext, $wallet),
            'alerts' => $this->buildAlerts($assets, $walletContext, $wallet),
        ];
    }

    /**
     * @param array<string, array<string, mixed>> $assets
     * @return array<int, string>
     */
    private function buildRiskFactors(array $assets, ?Wallet $wallet): array
    {
        $riskFactors = [];

        if (($assets['BITCOIN']['volatility'] ?? 0.0) >= 25.0) {
            $riskFactors[] = 'Bitcoin reste l actif le plus instable du panier et peut perturber une allocation trop concentree.';
        }

        if (($assets['USD/TND']['trend'] ?? 'STABLE') === 'UP' || ($assets['EUR/TND']['trend'] ?? 'STABLE') === 'UP') {
            $riskFactors[] = 'Le risque de change TND vers devises fortes reste present pour les paiements et transferts internationaux.';
        }

        if (($assets['GOLD']['trend'] ?? 'STABLE') === 'UP') {
            $riskFactors[] = 'La progression de l or traduit un biais prudent du marche, souvent associe a une recherche de couverture.';
        }

        if ($wallet instanceof Wallet) {
            $analytics = $this->walletAnalyticsService->buildAnalytics($wallet);
            $metrics = is_array($analytics['metrics'] ?? null) ? $analytics['metrics'] : [];

            if ((float) ($metrics['withdrawal_to_deposit_amount_ratio'] ?? 0.0) >= 1.8) {
                $riskFactors[] = 'Le wallet consomme actuellement plus de liquidite qu il n en accumule, ce qui reduit la marge de manoeuvre.';
            }
        }

        return $riskFactors;
    }

    /**
     * @param array<string, array<string, mixed>> $assets
     * @param array<string, mixed>|null $walletContext
     * @return array<int, string>
     */
    private function buildRecommendedActions(array $assets, string $marketSentiment, ?array $walletContext, ?Wallet $wallet): array
    {
        $actions = [];

        if (($assets['USD/TND']['trend'] ?? 'STABLE') === 'UP') {
            $actions[] = 'Pour un transfert USD depuis TND, privilegier une execution a court terme plutot qu un report prolonge.';
        }

        if (($assets['EUR/TND']['trend'] ?? 'STABLE') === 'UP') {
            $actions[] = 'Pour les besoins en EUR, preparer une reserve graduelle peut reduire le choc de conversion.';
        }

        if (($assets['GOLD']['trend'] ?? 'STABLE') === 'UP') {
            $actions[] = 'Pour l optimisation portefeuille, conserver une poche defensive moderee peut equilibrer le risque global.';
        }

        if (($assets['BITCOIN']['volatility'] ?? 0.0) >= 25.0) {
            $actions[] = 'Limiter l exposition Bitcoin a une part opportuniste et separer cette exposition du cash utile au wallet.';
        }

        if ($walletContext !== null && is_string($walletContext['portfolio_hint'] ?? null)) {
            $actions[] = (string) $walletContext['portfolio_hint'];
        }

        if ($wallet instanceof Wallet && $marketSentiment === 'defensive' && (float) $wallet->getSolde() < 1000) {
            $actions[] = 'Renforcer la liquidite disponible avant toute prise de position plus ambitieuse.';
        }

        return array_values(array_unique($actions));
    }

    /**
     * @param array<string, array<string, mixed>> $assets
     * @param array<string, mixed>|null $walletContext
     * @return array<int, string>
     */
    private function buildAlerts(array $assets, ?array $walletContext, ?Wallet $wallet): array
    {
        $alerts = [];

        if (($assets['BITCOIN']['volatility'] ?? 0.0) >= 30.0) {
            $alerts[] = 'Alerte volatilite: Bitcoin peut subir des ecarts de prix significatifs a horizon court.';
        }

        if (($assets['USD/TND']['predicted_1m'] ?? 0.0) > ($assets['USD/TND']['value_current'] ?? 0.0) * 1.015) {
            $alerts[] = 'Alerte change: USD/TND est projete en hausse sur 1 mois.';
        }

        if (($assets['EUR/TND']['predicted_3m'] ?? 0.0) > ($assets['EUR/TND']['value_current'] ?? 0.0) * 1.02) {
            $alerts[] = 'Alerte change: EUR/TND garde un biais haussier sur 3 mois.';
        }

        if ($walletContext !== null && is_string($walletContext['international_transfer_hint'] ?? null)) {
            $alerts[] = (string) $walletContext['international_transfer_hint'];
        }

        if ($wallet instanceof Wallet && $wallet->getEstBloque()) {
            $alerts[] = 'Alerte wallet: aucune execution sensible ne doit etre proposee tant que le wallet reste bloque.';
        }

        return array_values(array_unique($alerts));
    }
}
