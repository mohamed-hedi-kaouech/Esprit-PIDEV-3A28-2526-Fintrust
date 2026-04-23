<?php

namespace App\Service;

use App\Entity\Wallet\Wallet;

class MarketPredictorService
{
    /**
     * @var array<string, array<string, float|int|string>>
     */
    private const STATIC_ASSET_CONFIG = [
        'GOLD' => [
            'label' => 'Gold',
            'reference_value' => 232.40,
            'base_drift' => 0.018,
            'volatility' => 0.11,
            'seasonality' => 0.028,
            'confidence' => 79,
            'historical_accuracy' => 74.8,
        ],
        'BITCOIN' => [
            'label' => 'Bitcoin',
            'reference_value' => 64250.00,
            'base_drift' => 0.032,
            'volatility' => 0.34,
            'seasonality' => 0.075,
            'confidence' => 63,
            'historical_accuracy' => 61.2,
        ],
    ];

    public function __construct(
        private readonly ExchangeRateApiService $exchangeRateApiService,
        private readonly WalletAnalyticsService $walletAnalyticsService,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function buildMarketPredictions(?Wallet $wallet = null): array
    {
        $assets = [
            'USD/TND' => $this->buildCurrencyPrediction('USD', 'TND', 0.013, 0.055, 83, 78.4),
            'EUR/TND' => $this->buildCurrencyPrediction('EUR', 'TND', 0.009, 0.041, 86, 81.1),
            'GOLD' => $this->buildStaticAssetPrediction('GOLD'),
            'BITCOIN' => $this->buildStaticAssetPrediction('BITCOIN'),
        ];

        return [
            'generated_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'assets' => $assets,
            'wallet_context' => $this->buildWalletContext($wallet, $assets),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildCurrencyPrediction(
        string $sourceCurrency,
        string $targetCurrency,
        float $baseDrift,
        float $volatility,
        int $confidence,
        float $historicalAccuracy
    ): array {
        $rateData = $this->exchangeRateApiService->getLatestRate($sourceCurrency, $targetCurrency);
        $currentValue = round((float) $rateData['rate'], 4);
        $drift = $this->buildDeterministicDrift($sourceCurrency . $targetCurrency, $baseDrift);

        return $this->formatPredictionPayload(
            $sourceCurrency . '/' . $targetCurrency,
            $currentValue,
            $drift,
            $volatility,
            $confidence,
            $historicalAccuracy,
            (string) $rateData['provider']
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function buildStaticAssetPrediction(string $assetCode): array
    {
        $config = self::STATIC_ASSET_CONFIG[$assetCode];
        $referenceValue = (float) $config['reference_value'];
        $seasonality = (float) $config['seasonality'];
        $currentValue = round($referenceValue * (1 + $this->buildSeasonality($assetCode, $seasonality)), 2);
        $drift = $this->buildDeterministicDrift($assetCode, (float) $config['base_drift']);

        return $this->formatPredictionPayload(
            $assetCode,
            $currentValue,
            $drift,
            (float) $config['volatility'],
            (int) $config['confidence'],
            (float) $config['historical_accuracy'],
            'local_market_model'
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function formatPredictionPayload(
        string $asset,
        float $currentValue,
        float $drift,
        float $volatility,
        int $confidence,
        float $historicalAccuracy,
        string $provider
    ): array {
        $predicted1m = $this->projectValue($currentValue, $drift, $volatility, 1);
        $predicted3m = $this->projectValue($currentValue, $drift, $volatility, 3);
        $predicted6m = $this->projectValue($currentValue, $drift, $volatility, 6);

        return [
            'asset' => $asset,
            'provider' => $provider,
            'value_current' => $currentValue,
            'predicted_1m' => $predicted1m,
            'predicted_3m' => $predicted3m,
            'predicted_6m' => $predicted6m,
            'trend' => $this->resolveTrend($currentValue, $predicted3m, $volatility),
            'confidence' => max(52, min(94, $confidence - (int) round($volatility * 18))),
            'historical_accuracy' => round(max(55.0, min(91.0, $historicalAccuracy - ($volatility * 6))), 1),
            'volatility' => round($volatility * 100, 1),
        ];
    }

    private function projectValue(float $currentValue, float $drift, float $volatility, int $months): float
    {
        $stabilityPenalty = $volatility * 0.11 * sqrt($months);
        $grossGrowth = 1 + ($drift * $months) - $stabilityPenalty;

        if ($currentValue >= 1000) {
            return round($currentValue * $grossGrowth, 2);
        }

        return round($currentValue * $grossGrowth, 4);
    }

    private function resolveTrend(float $currentValue, float $projectedValue, float $volatility): string
    {
        $deltaRatio = $currentValue > 0 ? abs($projectedValue - $currentValue) / $currentValue : 0.0;
        $stableThreshold = max(0.01, $volatility * 0.05);

        if ($deltaRatio <= $stableThreshold) {
            return 'STABLE';
        }

        return $projectedValue > $currentValue ? 'UP' : 'DOWN';
    }

    private function buildDeterministicDrift(string $seed, float $baseDrift): float
    {
        $dayOfYear = (int) (new \DateTimeImmutable())->format('z') + 1;
        $seedFactor = ((crc32($seed) % 100) / 100) - 0.5;
        $seasonalFactor = sin($dayOfYear / 18);

        return round($baseDrift + ($seedFactor * 0.01) + ($seasonalFactor * 0.008), 4);
    }

    private function buildSeasonality(string $seed, float $amplitude): float
    {
        $dayOfYear = (int) (new \DateTimeImmutable())->format('z') + 1;
        $seedFactor = ((crc32($seed) % 25) + 5) / 100;

        return round(sin($dayOfYear / 14) * $amplitude * $seedFactor, 4);
    }

    /**
     * @param array<string, array<string, mixed>> $assets
     * @return array<string, mixed>|null
     */
    private function buildWalletContext(?Wallet $wallet, array $assets): ?array
    {
        if (!$wallet instanceof Wallet) {
            return null;
        }

        $analytics = $this->walletAnalyticsService->buildAnalytics($wallet);
        $metrics = is_array($analytics['metrics'] ?? null) ? $analytics['metrics'] : [];
        $currency = mb_strtoupper((string) $wallet->getDevise());
        $transferRatio = (float) ($metrics['withdrawal_to_deposit_amount_ratio'] ?? 0.0);

        return [
            'wallet_id' => $wallet->getIdWallet(),
            'currency' => $currency,
            'balance' => round((float) $wallet->getSolde(), 2),
            'recent_activity_label' => (string) ($metrics['recent_activity_label'] ?? 'stable'),
            'international_transfer_hint' => $this->buildTransferHint($currency, $transferRatio, $assets),
            'portfolio_hint' => $this->buildPortfolioHint($currency, $assets),
        ];
    }

    /**
     * @param array<string, array<string, mixed>> $assets
     */
    private function buildTransferHint(string $currency, float $transferRatio, array $assets): string
    {
        $usdTrend = (string) ($assets['USD/TND']['trend'] ?? 'STABLE');
        $eurTrend = (string) ($assets['EUR/TND']['trend'] ?? 'STABLE');

        if ($currency === 'TND' && ($usdTrend === 'UP' || $eurTrend === 'UP')) {
            return 'Pour les transferts sortants depuis un wallet TND, un envoi rapide limite le risque de change si USD/TND ou EUR/TND reste orientee a la hausse.';
        }

        if ($transferRatio >= 1.8) {
            return 'Votre wallet enregistre beaucoup de sorties recentes. Fractionner les transferts internationaux peut lisser le risque de timing.';
        }

        return 'Le contexte de change reste gerable pour des transferts planifies, avec verification du taux avant validation OTP.';
    }

    /**
     * @param array<string, array<string, mixed>> $assets
     */
    private function buildPortfolioHint(string $currency, array $assets): string
    {
        $goldTrend = (string) ($assets['GOLD']['trend'] ?? 'STABLE');
        $bitcoinVolatility = (float) ($assets['BITCOIN']['volatility'] ?? 0.0);

        if ($currency === 'TND' && $goldTrend === 'UP') {
            return 'Une exposition prudente aux actifs refuges ou aux devises fortes peut soutenir un portefeuille majoritairement TND.';
        }

        if ($bitcoinVolatility >= 25.0) {
            return 'Bitcoin reste utile pour la diversification opportuniste, mais pas comme socle principal d allocation.';
        }

        return 'Le portefeuille peut rester equilibre entre liquidite, devise forte et exposition limitee aux actifs plus volatils.';
    }
}
