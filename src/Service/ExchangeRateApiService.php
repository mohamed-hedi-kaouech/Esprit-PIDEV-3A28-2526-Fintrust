<?php

namespace App\Service;

use App\Exception\InternationalTransferException;
use Symfony\Component\HttpClient\Exception\ClientException;
use Symfony\Component\HttpClient\Exception\ServerException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Intl\Currencies;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

class ExchangeRateApiService
{
    private const API_BASE_URL = 'https://api.frankfurter.dev/v1';
    private const PROVIDER_LABEL = 'Frankfurter';
    private const FALLBACK_PROVIDER_LABEL = 'Fallback interne';
    private const SUPPORTED_CURRENCIES = ['TND', 'EUR', 'USD', 'GBP', 'CHF', 'CAD', 'JPY', 'AED', 'SAR'];
    private const REFERENCE_RATES = [
        'EUR' => 1.000000,
        'USD' => 1.090000,
        'GBP' => 0.860000,
        'CHF' => 0.970000,
        'CAD' => 1.480000,
        'JPY' => 164.200000,
        'AED' => 4.000000,
        'SAR' => 4.090000,
        'TND' => 3.360000,
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

        foreach (self::SUPPORTED_CURRENCIES as $currencyCode) {
            $label = Currencies::exists($currencyCode)
                ? sprintf('%s - %s', $currencyCode, Currencies::getName($currencyCode, 'fr'))
                : $currencyCode;

            $choices[$label] = $currencyCode;
        }

        return $choices;
    }

    /**
     * @return array{provider:string,date:string,source_currency:string,target_currency:string,rate:float}
     */
    public function getLatestRate(string $sourceCurrency, string $targetCurrency): array
    {
        $sourceCurrency = mb_strtoupper(trim($sourceCurrency));
        $targetCurrency = mb_strtoupper(trim($targetCurrency));

        if ($sourceCurrency === '' || $targetCurrency === '') {
            throw new InternationalTransferException('Les devises source et cible sont obligatoires.');
        }

        if (!in_array($sourceCurrency, self::SUPPORTED_CURRENCIES, true) || !in_array($targetCurrency, self::SUPPORTED_CURRENCIES, true)) {
            throw new InternationalTransferException('La paire de devises selectionnee n est pas supportee par la demonstration.');
        }

        if ($sourceCurrency === $targetCurrency) {
            return [
                'provider' => self::PROVIDER_LABEL,
                'date' => (new \DateTimeImmutable())->format('Y-m-d'),
                'source_currency' => $sourceCurrency,
                'target_currency' => $targetCurrency,
                'rate' => 1.0,
            ];
        }

        try {
            $response = $this->requestLatestRate($sourceCurrency, $targetCurrency);
            $payload = $this->decodePayload($response);
            $rate = $payload['rates'][$targetCurrency] ?? null;
            $date = $payload['date'] ?? null;

            if (!is_numeric($rate) || !is_string($date) || $date === '') {
                throw new InternationalTransferException('La reponse de l API de change est incomplete.');
            }

            return [
                'provider' => self::PROVIDER_LABEL,
                'date' => $date,
                'source_currency' => $sourceCurrency,
                'target_currency' => $targetCurrency,
                'rate' => round((float) $rate, 6),
            ];
        } catch (InternationalTransferException) {
            return $this->getFallbackRate($sourceCurrency, $targetCurrency);
        }
    }

    /**
     * @return array{provider:string,date:string,source_currency:string,target_currency:string,rate:float}
     */
    private function getFallbackRate(string $sourceCurrency, string $targetCurrency): array
    {
        $sourceReference = self::REFERENCE_RATES[$sourceCurrency] ?? null;
        $targetReference = self::REFERENCE_RATES[$targetCurrency] ?? null;

        if (!is_numeric($sourceReference) || !is_numeric($targetReference) || (float) $sourceReference <= 0) {
            throw new InternationalTransferException('Aucun taux de change de secours n est disponible pour cette paire.');
        }

        $rate = round(((float) $targetReference) / ((float) $sourceReference), 6);

        return [
            'provider' => self::FALLBACK_PROVIDER_LABEL,
            'date' => (new \DateTimeImmutable())->format('Y-m-d'),
            'source_currency' => $sourceCurrency,
            'target_currency' => $targetCurrency,
            'rate' => $rate,
        ];
    }

    private function requestLatestRate(string $sourceCurrency, string $targetCurrency): ResponseInterface
    {
        try {
            return $this->httpClient->request('GET', self::API_BASE_URL . '/latest', [
                'query' => [
                    'base' => $sourceCurrency,
                    'symbols' => $targetCurrency,
                ],
                'timeout' => 10,
            ]);
        } catch (\Throwable $throwable) {
            throw new InternationalTransferException(
                'Impossible de contacter le service de change externe pour le moment.',
                previous: $throwable
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function decodePayload(ResponseInterface $response): array
    {
        try {
            /** @var array<string, mixed> $payload */
            $payload = $response->toArray(false);
        } catch (ClientException|ServerException|\Throwable $throwable) {
            throw new InternationalTransferException(
                'Le service de change externe a retourne une erreur technique.',
                previous: $throwable
            );
        }

        $statusCode = $response->getStatusCode();
        if ($statusCode >= Response::HTTP_BAD_REQUEST) {
            $message = is_string($payload['message'] ?? null)
                ? $payload['message']
                : 'Erreur inconnue lors de la recuperation du taux de change.';

            throw new InternationalTransferException('API de change indisponible: ' . $message);
        }

        return $payload;
    }
}
