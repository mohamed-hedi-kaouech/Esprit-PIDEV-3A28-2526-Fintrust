<?php

namespace App\Service;

class EconomicDataService
{
    /**
     * @return array<string, mixed>
     */
    public function getOverview(): array
    {
        return [
            'inflationRate' => 4.2,
            'interestRate' => 6.75,
            'primaryCurrency' => 'TND',
            'globalTrend' => 'STABLE',
            'updatedAt' => '2026-04-17T10:30:00Z',
            'summary' => 'Les principaux indicateurs restent globalement stables avec une legere pression sur les taux et des devises encore maitrisees.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getInflation(): array
    {
        return [
            'name' => 'Inflation annuelle',
            'value' => 4.2,
            'unit' => '%',
            'period' => 'Mars 2026',
            'trend' => 'STABLE',
            'updatedAt' => '2026-04-17T10:30:00Z',
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getRates(): array
    {
        return [
            [
                'name' => 'Taux directeur',
                'value' => 6.75,
                'unit' => '%',
                'period' => 'Actuel',
                'trend' => 'UP',
                'updatedAt' => '2026-04-17T10:30:00Z',
            ],
            [
                'name' => 'Taux monetaire interbancaire',
                'value' => 6.32,
                'unit' => '%',
                'period' => 'Hebdomadaire',
                'trend' => 'STABLE',
                'updatedAt' => '2026-04-17T10:30:00Z',
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getCurrencies(): array
    {
        return [
            [
                'baseCurrency' => 'EUR',
                'targetCurrency' => 'TND',
                'rate' => 3.39,
                'changePercent' => 0.25,
                'trend' => 'UP',
                'updatedAt' => '2026-04-17T10:30:00Z',
            ],
            [
                'baseCurrency' => 'USD',
                'targetCurrency' => 'TND',
                'rate' => 3.12,
                'changePercent' => -0.14,
                'trend' => 'DOWN',
                'updatedAt' => '2026-04-17T10:30:00Z',
            ],
            [
                'baseCurrency' => 'GBP',
                'targetCurrency' => 'TND',
                'rate' => 3.93,
                'changePercent' => 0.08,
                'trend' => 'STABLE',
                'updatedAt' => '2026-04-17T10:30:00Z',
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getIndicators(): array
    {
        return [
            [
                'name' => 'Croissance PIB',
                'value' => 2.1,
                'unit' => '%',
                'period' => 'T1 2026',
                'trend' => 'UP',
                'updatedAt' => '2026-04-17T10:30:00Z',
            ],
            [
                'name' => 'Chomage',
                'value' => 15.4,
                'unit' => '%',
                'period' => 'T1 2026',
                'trend' => 'STABLE',
                'updatedAt' => '2026-04-17T10:30:00Z',
            ],
            [
                'name' => 'Balance commerciale',
                'value' => -1.8,
                'unit' => 'Md TND',
                'period' => 'Fev. 2026',
                'trend' => 'DOWN',
                'updatedAt' => '2026-04-17T10:30:00Z',
            ],
        ];
    }
}
