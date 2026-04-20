<?php

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;

class ExchangeRateApiService
{
    private const BASE_CURRENCY = 'EUR';

    /**
     * @var array<string, float>
     */
    private const REFERENCE_RATES = [
        'EUR' => 1.0000,
        'USD' => 1.0820,
        'GBP' => 0.8560,
        'CHF' => 0.9720,
        'CAD' => 1.4720,
        'AED' => 3.9730,
        'SAR' => 4.0570,
        'JPY' => 163.4000,
        'TND' => 3.3820,
    ];

    public function __construct(
        private readonly HttpClientInterface $httpClient,
    ) {
    }

    /**
     * @return array<string, string>
     */
    public function getSupportedCurrencyChoices(): array
    {
        $choices = [];
        foreach (array_keys(self::REFERENCE_RATES) as $currency) {
            $choices[$currency] = $currency;
        }

        return $choices;
    }

    /**
     * @return array{rate: float, date: string, provider: string}
     */
    public function getLatestRate(string $sourceCurrency, string $targetCurrency): array
    {
        $sourceCurrency = mb_strtoupper(trim($sourceCurrency));
        $targetCurrency = mb_strtoupper(trim($targetCurrency));

        if ($sourceCurrency === '' || $targetCurrency === '') {
            throw new \InvalidArgumentException('Les devises source et cible sont obligatoires.');
        }

        if ($sourceCurrency === $targetCurrency) {
            return [
                'rate' => 1.0,
                'date' => (new \DateTimeImmutable())->format('Y-m-d'),
                'provider' => 'local_reference_matrix',
            ];
        }

        $sourceBaseRate = self::REFERENCE_RATES[$sourceCurrency] ?? null;
        $targetBaseRate = self::REFERENCE_RATES[$targetCurrency] ?? null;

        if ($sourceBaseRate === null || $targetBaseRate === null) {
            throw new \InvalidArgumentException(sprintf('La paire de devises %s/%s n est pas supportee.', $sourceCurrency, $targetCurrency));
        }

        $crossRate = round($targetBaseRate / $sourceBaseRate, 6);

        return [
            'rate' => $crossRate,
            'date' => (new \DateTimeImmutable())->format('Y-m-d'),
            'provider' => 'local_reference_matrix:' . self::BASE_CURRENCY,
        ];
    }
}
