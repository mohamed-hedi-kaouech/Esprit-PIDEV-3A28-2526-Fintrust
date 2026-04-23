<?php

namespace App\Service;

use App\Entity\User\User;

class FinancialNewsService
{
    /**
     * @return list<array<string, mixed>>
     */
    public function getFinancialNews(array $filters = []): array
    {
        $items = $this->getMockArticles();

        if (!empty($filters['category'])) {
            $category = strtoupper((string) $filters['category']);
            $items = array_values(array_filter($items, static fn(array $item): bool => $item['category'] === $category));
        }

        if (!empty($filters['country'])) {
            $country = strtolower((string) $filters['country']);
            $items = array_values(array_filter($items, static fn(array $item): bool => strtolower((string) $item['country']) === $country));
        }

        if (!empty($filters['keyword'])) {
            $keyword = mb_strtolower((string) $filters['keyword']);
            $items = array_values(array_filter($items, static function (array $item) use ($keyword): bool {
                $haystack = mb_strtolower($item['title'] . ' ' . $item['summary'] . ' ' . implode(' ', $item['tags']));

                return str_contains($haystack, $keyword);
            }));
        }

        if (!empty($filters['source'])) {
            $source = mb_strtolower((string) $filters['source']);
            $items = array_values(array_filter($items, static fn(array $item): bool => mb_strtolower((string) $item['sourceName']) === $source));
        }

        if (!empty($filters['limit'])) {
            $items = array_slice($items, 0, max(1, (int) $filters['limit']));
        }

        return $items;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getMarketNews(int $limit = 6): array
    {
        return $this->getFinancialNews([
            'category' => 'MARKET',
            'limit' => $limit,
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getBankNews(int $limit = 6): array
    {
        return $this->getFinancialNews([
            'category' => 'BANKING',
            'limit' => $limit,
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getArticle(string $id): ?array
    {
        foreach ($this->getMockArticles() as $article) {
            if ($article['id'] === $id) {
                return $article;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    public function summarizeArticle(string $content): array
    {
        $sentiment = str_contains(mb_strtolower($content), 'hausse') || str_contains(mb_strtolower($content), 'progress') ? 'POSITIF' : 'NEUTRE';

        return [
            'summary' => mb_substr(trim($content), 0, 180) . (mb_strlen(trim($content)) > 180 ? '...' : ''),
            'keyPoints' => [
                'Impact potentiel sur les decisions de veille client.',
                'Lecture recommandee pour suivre les tendances de marche.',
                'A utiliser comme information et non comme conseil d investissement.',
            ],
            'sentiment' => $sentiment,
            'estimatedImpact' => $sentiment === 'POSITIF' ? 'Impact de marche modere a positif' : 'Impact informationnel a surveiller',
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getUserFeed(User $user, int $limit = 4): array
    {
        $categories = ['MARKET', 'BANKING'];

        if ($user->isVip()) {
            $categories[] = 'INVESTMENT';
        }

        if (!$user->isKycApproved()) {
            $categories[] = 'REGULATION';
        }

        if ($user->isAtRisk()) {
            $categories[] = 'ECONOMY';
        }

        $categories = array_values(array_unique($categories));

        $items = array_values(array_filter(
            $this->getMockArticles(),
            static fn(array $item): bool => in_array($item['category'], $categories, true)
        ));

        usort($items, static function (array $left, array $right): int {
            $weight = ['BREAKING' => 4, 'HIGH' => 3, 'MEDIUM' => 2, 'LOW' => 1];

            return ($weight[$right['importanceLevel']] ?? 0) <=> ($weight[$left['importanceLevel']] ?? 0);
        });

        return array_slice($items, 0, $limit);
    }

    /**
     * @return array<string, int>
     */
    public function getCategoryCounts(): array
    {
        $counts = [
            'MARKET' => 0,
            'BANKING' => 0,
            'ECONOMY' => 0,
            'REGULATION' => 0,
            'INVESTMENT' => 0,
        ];

        foreach ($this->getMockArticles() as $article) {
            $counts[$article['category']] = ($counts[$article['category']] ?? 0) + 1;
        }

        return $counts;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function getMockArticles(): array
    {
        return [
            [
                'id' => 'news_001',
                'title' => 'Les valeurs bancaires progressent apres une annonce sur les taux',
                'summary' => 'Les principales banques regionales enregistrent une progression moderee apres de nouvelles anticipations de politique monetaire.',
                'content' => 'Les grandes banques affichent une progression contenue apres des signaux plus clairs sur l orientation des taux. Les analystes restent prudents et rappellent que la volatilite peut persister selon les prochains indicateurs macroeconomiques.',
                'sourceName' => 'FinTrust Market Wire',
                'sourceUrl' => 'https://example.com/news/001',
                'publishedAt' => '2026-04-17T09:30:00Z',
                'category' => 'BANKING',
                'tags' => ['banques', 'taux', 'marches'],
                'imageUrl' => null,
                'language' => 'fr',
                'country' => 'tn',
                'importanceLevel' => 'HIGH',
                'sentiment' => 'POSITIF',
                'providerName' => 'MockNewsProvider',
            ],
            [
                'id' => 'news_002',
                'title' => 'Les indices europeens ouvrent dans un climat de vigilance',
                'summary' => 'Les marches actions debutent la seance avec prudence, portes par les publications macroeconomiques attendues dans la semaine.',
                'content' => 'La tendance de debut de seance reste partagee. Les investisseurs surveillent en priorite les chiffres d inflation et les commentaires des banques centrales.',
                'sourceName' => 'Market Pulse Europe',
                'sourceUrl' => 'https://example.com/news/002',
                'publishedAt' => '2026-04-17T08:10:00Z',
                'category' => 'MARKET',
                'tags' => ['bourse', 'indices', 'europe'],
                'imageUrl' => null,
                'language' => 'fr',
                'country' => 'eu',
                'importanceLevel' => 'MEDIUM',
                'sentiment' => 'NEUTRE',
                'providerName' => 'MockNewsProvider',
            ],
            [
                'id' => 'news_003',
                'title' => 'La regulation KYC se renforce sur les justificatifs numeriques',
                'summary' => 'De nouvelles recommandations insistent sur la qualite d image et la tracabilite des pieces de verification identitaire.',
                'content' => 'Les cadres de conformite rappellent l importance d une capture claire des documents et d une chaine de verification robuste pour les justificatifs numeriques.',
                'sourceName' => 'Compliance Brief',
                'sourceUrl' => 'https://example.com/news/003',
                'publishedAt' => '2026-04-16T17:45:00Z',
                'category' => 'REGULATION',
                'tags' => ['kyc', 'reglementation', 'conformite'],
                'imageUrl' => null,
                'language' => 'fr',
                'country' => 'tn',
                'importanceLevel' => 'HIGH',
                'sentiment' => 'NEUTRE',
                'providerName' => 'MockNewsProvider',
            ],
            [
                'id' => 'news_004',
                'title' => 'Les produits d epargne premium attirent a nouveau les clients prudents',
                'summary' => 'Les solutions d epargne a rendement modere retrouvent de l interet dans un contexte de recherche de stabilite.',
                'content' => 'Les investisseurs prudents se repositionnent sur des solutions plus lisibles, avec une preference pour les produits offrant de la visibilite et un cadre de risque simple.',
                'sourceName' => 'Savings Review',
                'sourceUrl' => 'https://example.com/news/004',
                'publishedAt' => '2026-04-16T13:20:00Z',
                'category' => 'INVESTMENT',
                'tags' => ['epargne', 'placements', 'stabilite'],
                'imageUrl' => null,
                'language' => 'fr',
                'country' => 'tn',
                'importanceLevel' => 'MEDIUM',
                'sentiment' => 'POSITIF',
                'providerName' => 'MockNewsProvider',
            ],
            [
                'id' => 'news_005',
                'title' => 'Les banques accelerent sur l experience mobile et la securite',
                'summary' => 'La convergence entre services mobiles, authentification forte et experience client reste un axe d investissement majeur.',
                'content' => 'Les institutions financieres priorisent les parcours mobiles, l authentification forte et une experience plus simple pour renforcer la fidelisation client.',
                'sourceName' => 'Banking Tech Journal',
                'sourceUrl' => 'https://example.com/news/005',
                'publishedAt' => '2026-04-15T18:05:00Z',
                'category' => 'BANKING',
                'tags' => ['mobile', 'banque', 'securite'],
                'imageUrl' => null,
                'language' => 'fr',
                'country' => 'global',
                'importanceLevel' => 'MEDIUM',
                'sentiment' => 'POSITIF',
                'providerName' => 'MockNewsProvider',
            ],
            [
                'id' => 'news_006',
                'title' => 'Les investisseurs surveillent les signaux de ralentissement economique',
                'summary' => 'Les donnees avancees invitent a davantage de prudence sur certains segments cycliques.',
                'content' => 'Le ton de marche devient plus selectif avec une attention croissante portee aux entreprises defensives et aux bilans solides.',
                'sourceName' => 'Eco Trends',
                'sourceUrl' => 'https://example.com/news/006',
                'publishedAt' => '2026-04-15T09:15:00Z',
                'category' => 'ECONOMY',
                'tags' => ['economie', 'ralentissement', 'macro'],
                'imageUrl' => null,
                'language' => 'fr',
                'country' => 'global',
                'importanceLevel' => 'HIGH',
                'sentiment' => 'NEUTRE',
                'providerName' => 'MockNewsProvider',
            ],
            [
                'id' => 'news_007',
                'title' => 'Les marches obligataires se detendent apres des chiffres rassurants',
                'summary' => 'Une publication macroeconomique mieux orientee detend les rendements et soutient l appetit pour les actifs de qualite.',
                'content' => 'Les rendements obligataires reperdent du terrain apres des indicateurs plus equilibres que prevu, favorisant un climat de marche moins tendu.',
                'sourceName' => 'Rates Monitor',
                'sourceUrl' => 'https://example.com/news/007',
                'publishedAt' => '2026-04-14T16:30:00Z',
                'category' => 'MARKET',
                'tags' => ['obligations', 'taux', 'qualite'],
                'imageUrl' => null,
                'language' => 'fr',
                'country' => 'global',
                'importanceLevel' => 'LOW',
                'sentiment' => 'POSITIF',
                'providerName' => 'MockNewsProvider',
            ],
            [
                'id' => 'news_008',
                'title' => 'A la une : les banques revoient leurs priorites de conformite',
                'summary' => 'Les grands etablissements renforcent les equipes de revue pour mieux suivre les pieces KYC et les anomalies documentaires.',
                'content' => 'La pression reglementaire pousse les banques a renforcer les dispositifs de controle documentaire, d archivage et de revue analytique des dossiers clients.',
                'sourceName' => 'FinTrust Insight Desk',
                'sourceUrl' => 'https://example.com/news/008',
                'publishedAt' => '2026-04-17T11:10:00Z',
                'category' => 'BANKING',
                'tags' => ['a la une', 'conformite', 'banques'],
                'imageUrl' => null,
                'language' => 'fr',
                'country' => 'tn',
                'importanceLevel' => 'BREAKING',
                'sentiment' => 'NEUTRE',
                'providerName' => 'MockNewsProvider',
            ],
        ];
    }
}
