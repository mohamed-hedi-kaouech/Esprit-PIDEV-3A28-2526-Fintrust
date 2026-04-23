<?php

namespace App\Service;

use Symfony\Component\HttpFoundation\RequestStack;

class MarketWatchService
{
    private const SESSION_KEY = 'fintrust.watchlist.symbols';

    public function __construct(
        private readonly RequestStack $requestStack,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function getMarketOverview(): array
    {
        return [
            'globalTrend' => 'STABLE',
            'updatedAt' => '2026-04-17T10:30:00Z',
            'summary' => 'Les grands actifs suivis affichent un climat de marche modere, avec une dispersion limitee entre hausses selectives et replis contenus.',
            'highlightsCount' => count($this->getHighlights()),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getWatchlist(): array
    {
        $symbols = $this->getSessionSymbols();
        $catalog = $this->getAssetCatalog();

        return array_values(array_filter(array_map(
            static fn(string $symbol): ?array => $catalog[$symbol] ?? null,
            $symbols
        )));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getWatchlistHighlights(int $limit = 3): array
    {
        return array_slice($this->getWatchlist(), 0, $limit);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getHighlights(): array
    {
        $assets = array_values($this->getAssetCatalog());
        usort($assets, static fn(array $left, array $right): int => abs($right['changePercent']) <=> abs($left['changePercent']));

        return array_slice($assets, 0, 5);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getTrending(): array
    {
        $assets = array_values($this->getAssetCatalog());
        usort($assets, static fn(array $left, array $right): int => $right['changePercent'] <=> $left['changePercent']);

        return array_slice($assets, 0, 6);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getAssetDetail(string $symbol): ?array
    {
        $catalog = $this->getAssetCatalog();

        if (!isset($catalog[$symbol])) {
            return null;
        }

        $asset = $catalog[$symbol];
        $asset['dayHigh'] = round($asset['currentValue'] * 1.017, 2);
        $asset['dayLow'] = round($asset['currentValue'] * 0.983, 2);
        $asset['summary'] = sprintf(
            '%s evolue dans une tendance %s avec une variation de %s%% sur la derniere mise a jour.',
            $asset['name'],
            strtolower($asset['trend']),
            number_format((float) $asset['changePercent'], 2, ',', ' ')
        );

        return $asset;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getDiscoverableAssets(): array
    {
        $watchlist = array_map(static fn(array $item): string => $item['symbol'], $this->getWatchlist());

        return array_values(array_filter(
            array_values($this->getAssetCatalog()),
            static fn(array $asset): bool => !in_array($asset['symbol'], $watchlist, true)
        ));
    }

    public function addToWatchlist(string $symbol): bool
    {
        $catalog = $this->getAssetCatalog();
        if (!isset($catalog[$symbol])) {
            return false;
        }

        $symbols = $this->getSessionSymbols();
        if (!in_array($symbol, $symbols, true)) {
            $symbols[] = $symbol;
            $this->storeSessionSymbols($symbols);
        }

        return true;
    }

    public function removeFromWatchlist(string $symbol): void
    {
        $symbols = array_values(array_filter(
            $this->getSessionSymbols(),
            static fn(string $item): bool => $item !== $symbol
        ));

        $this->storeSessionSymbols($symbols);
    }

    /**
     * @return list<string>
     */
    private function getSessionSymbols(): array
    {
        $session = $this->requestStack->getSession();
        $symbols = $session?->get(self::SESSION_KEY);

        if (!is_array($symbols) || $symbols === []) {
            $symbols = ['AAPL', 'BANKX', 'EUR/TND'];
            $this->storeSessionSymbols($symbols);
        }

        return array_values(array_filter($symbols, static fn(mixed $item): bool => is_string($item) && $item !== ''));
    }

    /**
     * @param list<string> $symbols
     */
    private function storeSessionSymbols(array $symbols): void
    {
        $this->requestStack->getSession()?->set(self::SESSION_KEY, array_values(array_unique($symbols)));
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function getAssetCatalog(): array
    {
        return [
            'AAPL' => [
                'symbol' => 'AAPL',
                'name' => 'Apple Inc.',
                'category' => 'ACTION',
                'currentValue' => 212.45,
                'change' => 3.18,
                'changePercent' => 1.52,
                'trend' => 'UP',
                'updatedAt' => '2026-04-17T10:30:00Z',
            ],
            'MSFT' => [
                'symbol' => 'MSFT',
                'name' => 'Microsoft Corp.',
                'category' => 'ACTION',
                'currentValue' => 428.15,
                'change' => 2.11,
                'changePercent' => 0.50,
                'trend' => 'UP',
                'updatedAt' => '2026-04-17T10:30:00Z',
            ],
            'BANKX' => [
                'symbol' => 'BANKX',
                'name' => 'Banking Index',
                'category' => 'INDICE',
                'currentValue' => 1284.22,
                'change' => -8.41,
                'changePercent' => -0.65,
                'trend' => 'DOWN',
                'updatedAt' => '2026-04-17T10:30:00Z',
            ],
            'BNK-TN' => [
                'symbol' => 'BNK-TN',
                'name' => 'Tunisia Banking Basket',
                'category' => 'BANQUE',
                'currentValue' => 94.80,
                'change' => 1.37,
                'changePercent' => 1.47,
                'trend' => 'UP',
                'updatedAt' => '2026-04-17T10:30:00Z',
            ],
            'EUR/TND' => [
                'symbol' => 'EUR/TND',
                'name' => 'Euro / Dinar tunisien',
                'category' => 'DEVISE',
                'currentValue' => 3.39,
                'change' => 0.01,
                'changePercent' => 0.25,
                'trend' => 'UP',
                'updatedAt' => '2026-04-17T10:30:00Z',
            ],
            'USD/TND' => [
                'symbol' => 'USD/TND',
                'name' => 'Dollar / Dinar tunisien',
                'category' => 'DEVISE',
                'currentValue' => 3.12,
                'change' => -0.01,
                'changePercent' => -0.14,
                'trend' => 'DOWN',
                'updatedAt' => '2026-04-17T10:30:00Z',
            ],
            'CAC40' => [
                'symbol' => 'CAC40',
                'name' => 'CAC 40',
                'category' => 'INDICE',
                'currentValue' => 8114.20,
                'change' => 22.80,
                'changePercent' => 0.28,
                'trend' => 'UP',
                'updatedAt' => '2026-04-17T10:30:00Z',
            ],
        ];
    }
}
