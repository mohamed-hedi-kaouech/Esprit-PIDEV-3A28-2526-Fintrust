<?php

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
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
        private readonly LoggerInterface $logger,
        private readonly string $exchangeRateApiKey,
        private readonly string $exchangeRateApiBaseUrl,
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
                'provider' => 'identity_rate',
            ];
        }

        if (!$this->isMissingOrPlaceholder($this->exchangeRateApiKey)) {
            return $this->fetchExchangeRateApiPair($sourceCurrency, $targetCurrency);
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

    /**
     * @return array{rate: float, date: string, provider: string}
     */
    private function fetchExchangeRateApiPair(string $sourceCurrency, string $targetCurrency): array
    {
        $url = sprintf(
            '%s/%s/pair/%s/%s',
            rtrim(trim($this->exchangeRateApiBaseUrl), '/'),
            rawurlencode(trim($this->exchangeRateApiKey)),
            rawurlencode($sourceCurrency),
            rawurlencode($targetCurrency)
        );

        try {
            $response = $this->httpClient->request('GET', $url, ['timeout' => 12]);
            $statusCode = $response->getStatusCode();
            $payload = $response->toArray(false);
        } catch (TransportExceptionInterface $exception) {
            $this->logger->error('Erreur reseau API conversion devise.', [
                'source_currency' => $sourceCurrency,
                'target_currency' => $targetCurrency,
                'message' => $exception->getMessage(),
            ]);

            throw new \RuntimeException('Le service de conversion de devise est temporairement indisponible.', previous: $exception);
        }

        if ($statusCode >= 400 || ($payload['result'] ?? null) !== 'success') {
            $this->logger->error('Erreur API conversion devise.', [
                'source_currency' => $sourceCurrency,
                'target_currency' => $targetCurrency,
                'status_code' => $statusCode,
                'payload' => $payload,
            ]);

            $errorType = (string) ($payload['error-type'] ?? 'unknown');
            throw new \RuntimeException('Conversion de devise refusee par le fournisseur: ' . $errorType);
        }

        $rate = (float) ($payload['conversion_rate'] ?? 0);
        if ($rate <= 0) {
            throw new \RuntimeException('Le fournisseur de conversion a retourne un taux invalide.');
        }

        $date = isset($payload['time_last_update_utc'])
            ? (new \DateTimeImmutable((string) $payload['time_last_update_utc']))->format('Y-m-d')
            : (new \DateTimeImmutable())->format('Y-m-d');

        return [
            'rate' => round($rate, 6),
            'date' => $date,
            'provider' => 'exchangerate-api:pair',
        ];
    }

    private function isMissingOrPlaceholder(string $value): bool
    {
        $normalized = trim($value);

        return $normalized === ''
            || str_contains($normalized, 'REMPLACE_PAR')
            || str_contains($normalized, 'VOTRE_')
            || str_contains($normalized, 'YOUR-API-KEY');
    }
}
