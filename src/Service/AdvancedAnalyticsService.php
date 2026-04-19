<?php

namespace App\Service;

use App\Entity\User\User;

class AdvancedAnalyticsService
{
    public function __construct(
        private readonly FinancialNewsService $financialNewsService,
    ) {}

    /**
     * @return array{userId:int,items:array<int,array<string,mixed>>,summary:array<string,mixed>}
     */
    public function getNewsRelevance(User $user, int $limit = 4): array
    {
        $feed = $this->financialNewsService->getUserFeed($user, max($limit, 6));
        $segment = $user->getClientSegment();
        $language = strtoupper($user->getPreferredLanguage());
        $watchThemes = $this->getUserThemes($user);

        $items = array_map(function (array $article) use ($segment, $watchThemes, $user, $language): array {
            $category = strtoupper((string) ($article['category'] ?? 'MARKET'));
            $importanceScore = match (strtoupper((string) ($article['importanceLevel'] ?? 'MEDIUM'))) {
                'BREAKING' => 96,
                'HIGH' => 88,
                'MEDIUM' => 72,
                default => 58,
            };

            $themeBoost = in_array($category, $watchThemes, true) ? 16 : 7;
            $segmentBoost = match ($segment) {
                User::SEGMENT_VIP => in_array($category, ['BANKING', 'RATES', 'INVESTMENT'], true) ? 13 : 8,
                User::SEGMENT_AT_RISK => in_array($category, ['REGULATION', 'BANKING'], true) ? 14 : 6,
                default => in_array($category, ['MARKET', 'SAVINGS', 'CURRENCIES'], true) ? 10 : 5,
            };
            $riskBoost = $user->isAtRisk() && in_array($category, ['REGULATION', 'BANKING'], true) ? 8 : 0;
            $relevanceScore = min(99, $importanceScore + $themeBoost + $segmentBoost + $riskBoost - 18);

            return [
                'articleId' => $article['id'] ?? uniqid('news_', true),
                'title' => $article['title'] ?? 'Actualite prioritaire',
                'category' => $category,
                'importanceScore' => $importanceScore,
                'relevanceScore' => $relevanceScore,
                'matchedSegments' => array_values(array_unique([$segment, $language === 'FR' ? 'FRANCOPHONE' : 'GLOBAL'])),
                'impactReason' => $this->buildImpactReason($user, $category),
                'priority' => $relevanceScore >= 88 ? 'HIGH' : ($relevanceScore >= 72 ? 'MEDIUM' : 'LOW'),
                'summary' => $article['summary'] ?? 'Resume analytique indisponible.',
                'sourceName' => $article['sourceName'] ?? 'FinTrust Market Wire',
                'publishedAt' => $article['publishedAt'] ?? (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            ];
        }, $feed);

        usort($items, static fn (array $left, array $right): int => $right['relevanceScore'] <=> $left['relevanceScore']);
        $items = array_values(array_slice($items, 0, $limit));

        return [
            'userId' => $user->getId(),
            'items' => $items,
            'summary' => [
                'segment' => $segment,
                'watchThemes' => $watchThemes,
                'globalPriority' => $items[0]['priority'] ?? 'LOW',
                'generatedAt' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            ],
        ];
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public function scoreNewsImpact(array $payload): array
    {
        $category = strtoupper((string) ($payload['category'] ?? 'MARKET'));
        $importance = (int) ($payload['importanceScore'] ?? 76);
        $matchedSegments = $payload['matchedSegments'] ?? ['STANDARD'];
        $impact = min(99, $importance + (in_array('VIP', $matchedSegments, true) ? 9 : 4) + (in_array($category, ['BANKING', 'RATES'], true) ? 8 : 3));

        return [
            'articleId' => $payload['articleId'] ?? 'news_demo',
            'category' => $category,
            'importanceScore' => $importance,
            'impactScore' => $impact,
            'priority' => $impact >= 85 ? 'HIGH' : ($impact >= 70 ? 'MEDIUM' : 'LOW'),
            'impactReason' => 'Scoring calcule selon la categorie, le niveau d importance et les segments cibles du contenu.',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function getNextBestAction(User $user): array
    {
        $actionType = 'NO_ACTION';
        $title = 'Aucune action critique';
        $description = 'Votre dossier est globalement stable et aucune action immediate n est requise.';
        $priorityScore = 24;
        $priorityLevel = 'LOW';
        $reason = 'Les signaux recents restent sous controle.';

        if (!$user->isKycApproved()) {
            $actionType = 'COMPLETE_KYC';
            $title = 'Completer la verification d identite';
            $description = 'Votre dossier KYC reste incomplet ou en attente et limite l acces a certains services.';
            $priorityScore = $user->getKycStatus() === User::KYC_EN_ATTENTE ? 82 : 93;
            $priorityLevel = $priorityScore >= 90 ? 'HIGH' : 'MEDIUM';
            $reason = $user->getKycStatus() === User::KYC_EN_ATTENTE
                ? 'Votre verification est engagee mais des validations complementaires restent necessaires.'
                : 'Le KYC non finalise bloque encore une partie des parcours FinTrust.';
        } elseif ($user->getRiskLevel() === User::RISK_CRITICAL || $user->getFraudScore() >= 75) {
            $actionType = 'MONITOR_ACCOUNT';
            $title = 'Surveiller le compte en priorite';
            $description = 'Une surveillance renforcee et une revue humaine sont recommandees sur ce profil.';
            $priorityScore = 96;
            $priorityLevel = 'CRITICAL';
            $reason = 'Le niveau de risque et le fraud score exigent une vigilance immediate.';
        } elseif ($user->getRiskLevel() === User::RISK_HIGH) {
            $actionType = 'ENABLE_2FA';
            $title = 'Activer la securite renforcee';
            $description = 'Ajoutez un facteur de verification supplementaire pour proteger vos operations sensibles.';
            $priorityScore = 84;
            $priorityLevel = 'HIGH';
            $reason = 'Le compte presente un niveau de risque eleve sur les derniers signaux comportementaux.';
        } elseif ($user->getTransactionFrequency() < 0.08) {
            $actionType = 'UPDATE_PROFILE';
            $title = 'Mettre a jour votre profil et vos preferences';
            $description = 'Rafraichissez vos informations pour maintenir un parcours plus fluide et des recommandations plus utiles.';
            $priorityScore = 63;
            $priorityLevel = 'MEDIUM';
            $reason = 'Le faible engagement recent suggere une relance douce et contextualisee.';
        }

        return [
            'userId' => $user->getId(),
            'actionType' => $actionType,
            'title' => $title,
            'description' => $description,
            'priorityScore' => $priorityScore,
            'priorityLevel' => $priorityLevel,
            'reason' => $reason,
            'recommendedAt' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'secondaryActions' => $this->buildSecondaryActions($user, $actionType),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function getActionPrioritySummary(User $user): array
    {
        $main = $this->getNextBestAction($user);

        return [
            'userId' => $user->getId(),
            'primaryAction' => $main,
            'priorityBand' => $main['priorityLevel'],
            'explanation' => 'La priorisation combine le statut KYC, le niveau de risque, les signaux de fraude et l engagement recent.',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function getDropoffRisk(User $user): array
    {
        $score = 18;
        $signals = [];

        if (!$user->isKycApproved()) {
            $score += 34;
            $signals[] = 'KYC non finalise ou encore en attente.';
        }
        if ($user->getTransactionFrequency() < 0.10) {
            $score += 22;
            $signals[] = 'Baisse nette de frequence d utilisation recente.';
        }
        if ($user->getStatus() === User::STATUS_EN_ATTENTE) {
            $score += 18;
            $signals[] = 'Compte encore en attente d activation complete.';
        }
        if ($user->getRiskLevel() === User::RISK_HIGH || $user->getRiskLevel() === User::RISK_CRITICAL) {
            $score += 14;
            $signals[] = 'Contexte de risque susceptible de freiner l engagement.';
        }

        $score = min(96, $score);
        $riskLevel = $score >= 80 ? 'HIGH' : ($score >= 55 ? 'MEDIUM' : 'LOW');
        $engagement = $score >= 78 ? 'AT_RISK' : ($user->getTransactionFrequency() < 0.15 ? 'INACTIVE' : 'ENGAGED');

        return [
            'userId' => $user->getId(),
            'dropoffRiskScore' => $score,
            'riskLevel' => $riskLevel,
            'engagementLevel' => $engagement,
            'topSignals' => $signals !== [] ? $signals : ['Parcours globalement stable et regulier.'],
            'recommendedIntervention' => $score >= 80 ? 'Relance ciblee et accompagnement humain sur les etapes bloquees.' : 'Suivi standard avec mise en avant des prochaines actions utiles.',
            'lastEngagementDate' => ($user->getBehaviorUpdatedAt() ?? $user->getCreatedAt())->format(\DateTimeInterface::ATOM),
        ];
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public function getEngagementAnalysis(User $user, array $payload = []): array
    {
        $profile = $this->getDropoffRisk($user);
        $profile['timeline'] = [
            ['label' => 'Derniere analyse', 'value' => 'Signaux comportementaux consolides'],
            ['label' => 'Variation activite', 'value' => $user->getTransactionFrequency() < 0.12 ? 'Baisse recente detectee' : 'Activite relativement stable'],
            ['label' => 'Intervention suggeree', 'value' => $profile['recommendedIntervention']],
        ];
        $profile['inputSignals'] = $payload;

        return $profile;
    }

    /**
     * @return array<string,mixed>
     */
    public function getIdentityConsistency(User $user): array
    {
        $issues = [];
        $score = 91;

        if (!$user->isKycApproved()) {
            $score -= 18;
            $issues[] = [
                'field' => 'kyc',
                'issueType' => 'PENDING',
                'description' => 'Le dossier KYC n est pas encore approuve, ce qui reduit le niveau de confiance global.',
            ];
        }
        if ($user->getNumTel() === null || trim($user->getNumTel()) === '') {
            $score -= 8;
            $issues[] = [
                'field' => 'telephone',
                'issueType' => 'MISSING',
                'description' => 'Le numero de telephone reste absent ou insuffisamment confirme.',
            ];
        }
        if ($user->getRiskLevel() === User::RISK_HIGH || $user->getRiskLevel() === User::RISK_CRITICAL) {
            $score -= 10;
            $issues[] = [
                'field' => 'historique',
                'issueType' => 'VIGILANCE',
                'description' => 'Des signaux de risque augmentent la necessite de revue humaine.',
            ];
        }

        $score = max(42, $score);
        $consistencyLevel = $score >= 88 ? 'HIGH' : ($score >= 72 ? 'MEDIUM' : 'LOW');

        return [
            'userId' => $user->getId(),
            'identityScore' => $score,
            'consistencyLevel' => $consistencyLevel,
            'documentMatches' => $score >= 75,
            'declaredDataMatches' => count($issues) <= 1,
            'issues' => $issues,
            'manualReviewRequired' => $score < 80,
            'confidenceLevel' => $score >= 85 ? 'HIGH' : 'MEDIUM',
            'documentConsistency' => [
                ['document' => 'Identite principale', 'status' => $score >= 75 ? 'Cohérent' : 'A verifier', 'score' => min(98, $score + 4)],
                ['document' => 'Justificatif complementaire', 'status' => $score >= 80 ? 'Exploitable' : 'Partiel', 'score' => max(51, $score - 6)],
            ],
        ];
    }

    /**
     * @param iterable<User> $users
     * @return array<int,array<string,mixed>>
     */
    public function getAtRiskUsers(iterable $users, int $limit = 8): array
    {
        $items = [];
        foreach ($users as $user) {
            if (!$user instanceof User || $user->isAdmin()) {
                continue;
            }

            $risk = $this->getDropoffRisk($user);
            $action = $this->getNextBestAction($user);
            $items[] = [
                'userId' => $user->getId(),
                'name' => $user->getFullName(),
                'segment' => $user->getClientSegment(),
                'kycStatus' => $user->getKycStatus() ?? 'NON_SOUMIS',
                'riskLevel' => $risk['riskLevel'],
                'dropoffRiskScore' => $risk['dropoffRiskScore'],
                'engagementLevel' => $risk['engagementLevel'],
                'mainSignal' => $risk['topSignals'][0] ?? 'Parcours stable.',
                'recommendedIntervention' => $risk['recommendedIntervention'],
                'actionTitle' => $action['title'],
            ];
        }

        usort($items, static fn (array $left, array $right): int => $right['dropoffRiskScore'] <=> $left['dropoffRiskScore']);

        return array_values(array_slice($items, 0, $limit));
    }

    /**
     * @param iterable<User> $users
     * @return array<string,mixed>
     */
    public function getAdminAnalyticsOverview(iterable $users): array
    {
        $clients = [];
        foreach ($users as $user) {
            if ($user instanceof User && !$user->isAdmin()) {
                $clients[] = $user;
            }
        }

        $atRisk = $this->getAtRiskUsers($clients, 50);
        $critical = count(array_filter($clients, static fn (User $user): bool => $user->isCriticalRisk()));
        $pendingKyc = count(array_filter($clients, static fn (User $user): bool => !$user->isKycApproved()));
        $vip = count(array_filter($clients, static fn (User $user): bool => $user->isVip()));

        return [
            'kpis' => [
                'clientsAnalyses' => count($clients),
                'atRiskUsers' => count(array_filter($atRisk, static fn (array $item): bool => $item['dropoffRiskScore'] >= 70)),
                'criticalRiskUsers' => $critical,
                'pendingKyc' => $pendingKyc,
                'vipMonitored' => $vip,
            ],
            'headline' => 'Le bundle analytique consolide les signaux KYC, risque, actualites et engagement pour prioriser les actions admin.',
            'prioritySignals' => [
                'Hausse des utilisateurs a relancer sur les parcours KYC incomplets.',
                'Pression de risque concentree sur quelques segments a faible activite.',
                'Actions recommandées dominées par la completion KYC et la securite renforcee.',
            ],
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function getKycTrends(): array
    {
        return [
            ['period' => 'Lun', 'approved' => 18, 'review' => 7, 'rejected' => 3],
            ['period' => 'Mar', 'approved' => 22, 'review' => 6, 'rejected' => 2],
            ['period' => 'Mer', 'approved' => 20, 'review' => 9, 'rejected' => 4],
            ['period' => 'Jeu', 'approved' => 24, 'review' => 8, 'rejected' => 3],
            ['period' => 'Ven', 'approved' => 27, 'review' => 10, 'rejected' => 4],
        ];
    }

    /**
     * @param iterable<User> $users
     * @return array<string,mixed>
     */
    public function getRiskPatterns(iterable $users): array
    {
        $patterns = [
            ['label' => 'Segments a surveiller', 'value' => 'STANDARD fragile', 'tone' => 'amber'],
            ['label' => 'Motif dominant', 'value' => 'KYC incomplet', 'tone' => 'blue'],
            ['label' => 'Pic d anomalies', 'value' => 'Identite partielle', 'tone' => 'red'],
        ];

        $distribution = ['LOW' => 0, 'MEDIUM' => 0, 'HIGH' => 0, 'CRITICAL' => 0];
        foreach ($users as $user) {
            if ($user instanceof User && !$user->isAdmin()) {
                $distribution[$user->getRiskLevel()]++;
            }
        }

        return [
            'patterns' => $patterns,
            'distribution' => $distribution,
            'heatmap' => [
                ['zone' => 'Identite', 'intensity' => 72],
                ['zone' => 'KYC', 'intensity' => 81],
                ['zone' => 'Engagement', 'intensity' => 64],
                ['zone' => 'Fraude', 'intensity' => 58],
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function getSupportInsights(): array
    {
        return [
            'topCategories' => [
                ['label' => 'Relance KYC', 'count' => 14],
                ['label' => 'Verification identite', 'count' => 9],
                ['label' => 'Securite du compte', 'count' => 7],
            ],
            'averageResolutionTime' => '5 h 20',
            'serviceNote' => 'Les demandes support a forte valeur restent concentrees sur le KYC et la remise en confiance apres blocage documentaire.',
        ];
    }

    /**
     * @param iterable<User> $users
     * @param array<int, array<string, mixed>> $pendingRequests
     * @return array<string, mixed>
     */
    public function getAccountDeactivationInsights(iterable $users, array $pendingRequests = []): array
    {
        $hypotheses = [];

        foreach ($users as $user) {
            if (!$user instanceof User || $user->isAdmin()) {
                continue;
            }

            $dropoff = $this->getDropoffRisk($user);
            $requestWeight = isset($pendingRequests[$user->getId()]) ? 16 : 0;
            $volatilityWeight = $user->getRiskLevel() === User::RISK_HIGH || $user->getRiskLevel() === User::RISK_CRITICAL ? 10 : 0;
            $kycWeight = !$user->isKycApproved() ? 12 : 0;
            $frequencyPenalty = $user->getTransactionFrequency() < 0.08 ? 14 : ($user->getTransactionFrequency() < 0.14 ? 7 : 0);

            $score = min(97, $dropoff['dropoffRiskScore'] + $requestWeight + $volatilityWeight + $kycWeight + $frequencyPenalty);
            $hypotheses[] = [
                'userId' => $user->getId(),
                'name' => $user->getFullName(),
                'email' => $user->getEmail(),
                'deactivationProbability' => $score,
                'deactivationBand' => $score >= 82 ? 'CRITIQUE' : ($score >= 66 ? 'ELEVE' : ($score >= 48 ? 'MODERE' : 'FAIBLE')),
                'mainHypothesis' => $this->buildDeactivationHypothesis($user, $dropoff, isset($pendingRequests[$user->getId()])),
                'segment' => $user->getClientSegment(),
                'status' => $user->getStatus(),
                'hasPendingRequest' => isset($pendingRequests[$user->getId()]),
                'recommendedAction' => isset($pendingRequests[$user->getId()])
                    ? 'Revoir la demande formelle et confirmer la fermeture selon la justification fournie.'
                    : ($score >= 70
                        ? 'Contacter le client, clarifier les blocages et proposer une retention contextualisee.'
                        : 'Maintenir un suivi discret avec des actions de reassurance.'),
            ];
        }

        usort($hypotheses, static fn (array $left, array $right): int => $right['deactivationProbability'] <=> $left['deactivationProbability']);

        $criticalCount = count(array_filter($hypotheses, static fn (array $item): bool => $item['deactivationProbability'] >= 82));
        $highCount = count(array_filter($hypotheses, static fn (array $item): bool => $item['deactivationProbability'] >= 66));

        return [
            'summary' => [
                'pendingRequests' => count($pendingRequests),
                'criticalHypotheses' => $criticalCount,
                'highProbabilityUsers' => $highCount,
                'headline' => 'FinTrust croise engagement, statut KYC, niveau de risque et demandes formelles pour anticiper les desactivations de compte.',
            ],
            'hypotheses' => array_slice($hypotheses, 0, 12),
        ];
    }

    /**
     * @return array<int,string>
     */
    private function getUserThemes(User $user): array
    {
        $themes = ['BANKING', 'MARKET'];

        if ($user->isVip()) {
            $themes[] = 'INVESTMENT';
            $themes[] = 'RATES';
        }
        if ($user->getPreferredLanguage() === User::LANGUAGE_EN) {
            $themes[] = 'CURRENCIES';
        }
        if ($user->isAtRisk()) {
            $themes[] = 'REGULATION';
        }

        return array_values(array_unique($themes));
    }

    private function buildImpactReason(User $user, string $category): string
    {
        return match ($category) {
            'BANKING' => $user->isVip()
                ? 'Actualite prioritaire pour un client premium sensible aux evolutions bancaires et aux produits a forte valeur.'
                : 'Contenu bancaire utile pour contextualiser votre relation avec les services FinTrust.',
            'RATES' => 'Le profil montre une sensibilite aux evolutions de taux et a leurs effets sur les produits d epargne et de financement.',
            'CURRENCIES' => 'Les devises suivies et le contexte de marche rendent cette information particulierement exploitable.',
            'REGULATION' => 'Les signaux de risque et de conformite donnent a cette actualite une portee plus forte pour ce profil.',
            default => 'Le matching combine votre segment, votre niveau de risque et les centres d interet implicites observes sur FinTrust.',
        };
    }

    /**
     * @param array<string, mixed> $dropoff
     */
    private function buildDeactivationHypothesis(User $user, array $dropoff, bool $hasPendingRequest): string
    {
        if ($hasPendingRequest) {
            return 'Le client a deja exprime une intention de fermeture de compte, ce qui augmente fortement la probabilite de desactivation.';
        }

        if (!$user->isKycApproved()) {
            return 'Le dossier KYC incomplet fragilise l adoption et peut pousser le client a abandonner son compte.';
        }

        if ($user->getTransactionFrequency() < 0.08) {
            return 'La faible activite recente suggere un desengagement progressif et une possible sortie du parcours FinTrust.';
        }

        if (($dropoff['riskLevel'] ?? 'LOW') === 'HIGH') {
            return 'Les signaux combines de risque et de friction font emerger une hypothese de retrait ou de fermeture de compte.';
        }

        return 'Le profil reste globalement stable, mais certains signaux faibles invitent a une veille preventive.';
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function buildSecondaryActions(User $user, string $primaryAction): array
    {
        $actions = [];

        if ($primaryAction !== 'ENABLE_2FA') {
            $actions[] = [
                'actionType' => 'ENABLE_2FA',
                'title' => 'Renforcer la securite du compte',
                'priorityLevel' => $user->isAtRisk() ? 'HIGH' : 'MEDIUM',
            ];
        }
        if ($primaryAction !== 'UPDATE_PROFILE') {
            $actions[] = [
                'actionType' => 'UPDATE_PROFILE',
                'title' => 'Mettre a jour les preferences et informations du profil',
                'priorityLevel' => 'MEDIUM',
            ];
        }
        if ($primaryAction !== 'COMPLETE_KYC' && !$user->isKycApproved()) {
            $actions[] = [
                'actionType' => 'COMPLETE_KYC',
                'title' => 'Finaliser le dossier KYC',
                'priorityLevel' => 'HIGH',
            ];
        }

        return $actions;
    }
}
