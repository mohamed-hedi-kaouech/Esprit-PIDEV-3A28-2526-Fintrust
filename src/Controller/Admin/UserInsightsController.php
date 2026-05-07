<?php

namespace App\Controller\Admin;

use App\Entity\User\User;
use App\Repository\UserRepository;
use App\Service\AccountDeactivationRequestService;
use App\Service\AdvancedAnalyticsService;
use App\Service\UserIntelligenceService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
#[Route('/admin/utilisateurs', name: 'admin_user_')]
class UserInsightsController extends AbstractController
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly UserIntelligenceService $userIntelligenceService,
        private readonly AdvancedAnalyticsService $advancedAnalyticsService,
        private readonly AccountDeactivationRequestService $accountDeactivationRequestService,
    ) {}

    #[Route('/insights', name: 'insights', methods: ['GET'])]
    public function index(): Response
    {
        /** @var User[] $clients */
        $clients = $this->userRepository->findBy(
            ['role' => User::ROLE_CLIENT],
            ['createdAt' => 'DESC'],
            180
        );

        $pendingDeactivationRequests = $this->accountDeactivationRequestService->getPendingForUsers($clients);
        $pendingDeactivationMap = [];

        foreach ($pendingDeactivationRequests as $request) {
            $userId = (int) ($request['userId'] ?? 0);
            if ($userId > 0) {
                $pendingDeactivationMap[$userId] = $request;
            }
        }

        $userRows = [];
        $trustScores = [];
        $kycValidated = 0;
        $incompleteProfiles = 0;
        $securityAlerts = 0;
        $accountsToVerify = 0;
        $protectedAccounts = 0;
        $emailVerified = 0;
        $suspiciousDevices = 0;
        $recentlyActive = 0;
        $completionTotal = 0;

        foreach ($clients as $user) {
            $enrichment = $this->userIntelligenceService->buildProfileEnrichment($user);
            $riskProfile = $this->userIntelligenceService->getRiskProfile($user);
            $dropoff = $this->advancedAnalyticsService->getDropoffRisk($user);
            $nextAction = $this->advancedAnalyticsService->getNextBestAction($user);
            $timeline = $this->userIntelligenceService->buildMonitoringTimeline($user);

            $profileCompletion = $this->computeProfileCompletion($user);
            $completionTotal += $profileCompletion;
            $trustScore = (int) ($enrichment['trustScore'] ?? 0);
            $trustScores[] = $trustScore;

            if ($user->isKycApproved()) {
                $kycValidated++;
            }

            if ($profileCompletion < 80 || !$user->isVerified() || $user->getNumTel() === null || trim((string) $user->getNumTel()) === '') {
                $incompleteProfiles++;
            }

            if (
                in_array($user->getRiskLevel(), [User::RISK_HIGH, User::RISK_CRITICAL], true)
                || $user->getFraudScore() >= 60
                || isset($pendingDeactivationMap[$user->getId()])
            ) {
                $securityAlerts++;
            }

            if (
                !$user->isKycApproved()
                || !$user->isVerified()
                || $user->getStatus() !== User::STATUS_ACTIF
                || in_array($user->getRiskLevel(), [User::RISK_HIGH, User::RISK_CRITICAL], true)
            ) {
                $accountsToVerify++;
            }

            if ($user->isVerified()) {
                $emailVerified++;
            }

            if ($user->isVerified() && $user->isKycApproved() && $user->getStatus() === User::STATUS_ACTIF) {
                $protectedAccounts++;
            }

            if ($user->getFraudScore() >= 70 || $user->isCriticalRisk()) {
                $suspiciousDevices++;
            }

            if ($user->getTransactionFrequency() >= 0.30) {
                $recentlyActive++;
            }

            $priorityScore = min(
                100,
                (int) round(
                    ((int) ($nextAction['priorityScore'] ?? 0) * 0.45)
                    + ((int) ($dropoff['dropoffRiskScore'] ?? 0) * 0.35)
                    + ((100 - $trustScore) * 0.20)
                )
            );

            $userRows[] = [
                'user' => $user,
                'trustScore' => $trustScore,
                'riskProfile' => $riskProfile,
                'dropoff' => $dropoff,
                'nextAction' => $nextAction,
                'timeline' => $timeline,
                'profileCompletion' => $profileCompletion,
                'priorityScore' => $priorityScore,
                'securityTone' => $this->resolveSecurityTone($user),
                'accountState' => $this->resolveAccountState($user),
                'securityLabel' => $this->resolveSecurityLabel($user),
                'activityLabel' => $this->resolveActivityLabel($user),
                'activityTone' => $this->resolveActivityTone($user),
            ];
        }

        usort(
            $userRows,
            static fn (array $left, array $right): int => ($right['priorityScore'] <=> $left['priorityScore'])
                ?: ($right['trustScore'] <=> $left['trustScore'])
        );

        $priorityUsers = array_slice($userRows, 0, 6);
        $highlightUser = $priorityUsers[0] ?? null;
        $highlightJourney = $highlightUser !== null ? $this->buildJourneySteps($highlightUser['user']) : [];
        $averageTrust = $trustScores !== [] ? (int) round(array_sum($trustScores) / count($trustScores)) : 0;
        $averageCompletion = $userRows !== [] ? (int) round($completionTotal / count($userRows)) : 0;
        $advancedOverview = $this->advancedAnalyticsService->getAdminAnalyticsOverview($clients);
        $riskPatterns = $this->advancedAnalyticsService->getRiskPatterns($clients);
        $deactivationInsights = $this->advancedAnalyticsService->getAccountDeactivationInsights($clients, $pendingDeactivationMap);

        $segmentation = [
            [
                'label' => 'Nouveau client',
                'value' => count(array_filter($clients, fn (User $user): bool => $user->getCreatedAt() >= new \DateTimeImmutable('-30 days'))),
                'share' => $this->computeShare($clients, count(array_filter($clients, fn (User $user): bool => $user->getCreatedAt() >= new \DateTimeImmutable('-30 days')))),
                'icon' => 'bi-person-plus',
                'tone' => 'blue',
            ],
            [
                'label' => 'Client fiable',
                'value' => count(array_filter($clients, fn (User $user): bool => $user->isKycApproved() && $user->isVerified() && $user->getRiskLevel() === User::RISK_LOW)),
                'share' => $this->computeShare($clients, count(array_filter($clients, fn (User $user): bool => $user->isKycApproved() && $user->isVerified() && $user->getRiskLevel() === User::RISK_LOW))),
                'icon' => 'bi-shield-check',
                'tone' => 'green',
            ],
            [
                'label' => 'A verifier',
                'value' => $accountsToVerify,
                'share' => $this->computeShare($clients, $accountsToVerify),
                'icon' => 'bi-search',
                'tone' => 'amber',
            ],
            [
                'label' => 'Premium',
                'value' => count(array_filter($clients, fn (User $user): bool => $user->isVip())),
                'share' => $this->computeShare($clients, count(array_filter($clients, fn (User $user): bool => $user->isVip()))),
                'icon' => 'bi-gem',
                'tone' => 'violet',
            ],
        ];

        $securityOverview = [
            [
                'label' => 'Profils proteges',
                'value' => $this->computeShare($clients, $protectedAccounts),
                'detail' => 'Utilisateurs verifies, KYC valides et comptes actifs',
                'icon' => 'bi-lock',
                'tone' => 'blue',
            ],
            [
                'label' => 'Emails verifies',
                'value' => $this->computeShare($clients, $emailVerified),
                'detail' => 'Adresses confirmees et prêtes pour les alertes',
                'icon' => 'bi-envelope-check',
                'tone' => 'green',
            ],
            [
                'label' => 'Appareils suspects',
                'value' => $this->computeShare($clients, $suspiciousDevices),
                'detail' => 'Signaux fraude ou profils critiques a surveiller',
                'icon' => 'bi-phone',
                'tone' => 'red',
            ],
        ];

        $healthCards = [
            [
                'label' => 'Profil complete',
                'value' => $averageCompletion . '%',
                'detail' => 'Moyenne des informations utiles par compte',
                'badge' => $averageCompletion >= 85 ? 'Bon' : 'A renforcer',
                'tone' => $averageCompletion >= 85 ? 'green' : 'amber',
                'icon' => 'bi-person',
            ],
            [
                'label' => 'Verification KYC',
                'value' => $kycValidated > 0 ? 'Valide' : 'A lancer',
                'detail' => $kycValidated . ' profils conformes sur ' . count($clients),
                'badge' => $kycValidated >= max(1, (int) round(count($clients) * 0.7)) ? 'Valide' : 'Suivi',
                'tone' => $kycValidated >= max(1, (int) round(count($clients) * 0.7)) ? 'green' : 'amber',
                'icon' => 'bi-shield-check',
            ],
            [
                'label' => 'Securite du compte',
                'value' => $securityAlerts <= max(2, (int) round(count($clients) * 0.12)) ? 'Moyenne' : 'Vigilance',
                'detail' => $securityAlerts . ' alerte(s) prioritaire(s) en cours',
                'badge' => $securityAlerts <= max(2, (int) round(count($clients) * 0.12)) ? 'Stable' : 'Attention',
                'tone' => $securityAlerts <= max(2, (int) round(count($clients) * 0.12)) ? 'blue' : 'red',
                'icon' => 'bi-lock',
            ],
            [
                'label' => 'Activite recente',
                'value' => $recentlyActive >= max(1, (int) round(count($clients) * 0.45)) ? 'Stable' : 'A relancer',
                'detail' => $recentlyActive . ' comptes montrent un usage recent',
                'badge' => $recentlyActive >= max(1, (int) round(count($clients) * 0.45)) ? 'Stable' : 'Faible',
                'tone' => $recentlyActive >= max(1, (int) round(count($clients) * 0.45)) ? 'green' : 'amber',
                'icon' => 'bi-activity',
            ],
        ];

        $recommendedActions = $this->buildRecommendedActions(
            $accountsToVerify,
            $incompleteProfiles,
            $securityAlerts,
            $deactivationInsights['summary']['pendingRequests'] ?? 0
        );

        $personalizedNotifications = $this->buildPersonalizedNotifications(
            $accountsToVerify,
            $incompleteProfiles,
            $securityAlerts,
            $pendingDeactivationRequests,
            $priorityUsers
        );

        return $this->render('admin/users/insights.html.twig', [
            'totalClients' => count($clients),
            'averageTrust' => $averageTrust,
            'kycValidated' => $kycValidated,
            'incompleteProfiles' => $incompleteProfiles,
            'securityAlerts' => $securityAlerts,
            'accountsToVerify' => $accountsToVerify,
            'advancedOverview' => $advancedOverview,
            'riskPatterns' => $riskPatterns,
            'highlightUser' => $highlightUser,
            'highlightJourney' => $highlightJourney,
            'segmentation' => $segmentation,
            'securityOverview' => $securityOverview,
            'healthCards' => $healthCards,
            'recommendedActions' => $recommendedActions,
            'priorityUsers' => $priorityUsers,
            'personalizedNotifications' => $personalizedNotifications,
        ]);
    }

    private function computeProfileCompletion(User $user): int
    {
        $score = 35;

        if ($user->getNumTel() !== null && trim($user->getNumTel()) !== '') {
            $score += 20;
        }

        if ($user->isVerified()) {
            $score += 20;
        }

        if ($user->isKycApproved()) {
            $score += 25;
        }

        return min(100, $score);
    }

    /**
     * @param User[] $users
     */
    private function computeShare(array $users, int $value): int
    {
        if ($users === []) {
            return 0;
        }

        return (int) round(($value / count($users)) * 100);
    }

    private function resolveSecurityTone(User $user): string
    {
        return match (true) {
            $user->isCriticalRisk(), $user->getFraudScore() >= 75 => 'red',
            $user->getRiskLevel() === User::RISK_HIGH => 'amber',
            default => 'green',
        };
    }

    private function resolveSecurityLabel(User $user): string
    {
        return match (true) {
            $user->isCriticalRisk(), $user->getFraudScore() >= 75 => 'Elevee',
            $user->getRiskLevel() === User::RISK_HIGH => 'Moyenne',
            default => 'Saine',
        };
    }

    private function resolveActivityLabel(User $user): string
    {
        if ($user->getTransactionFrequency() >= 0.80) {
            return 'Active';
        }

        if ($user->getTransactionFrequency() >= 0.25) {
            return 'Stable';
        }

        return 'Faible';
    }

    private function resolveActivityTone(User $user): string
    {
        if ($user->getTransactionFrequency() >= 0.80) {
            return 'green';
        }

        if ($user->getTransactionFrequency() >= 0.25) {
            return 'blue';
        }

        return 'amber';
    }

    /**
     * @return array{label:string,tone:string}
     */
    private function resolveAccountState(User $user): array
    {
        if ($user->isVip()) {
            return ['label' => 'Premium', 'tone' => 'violet'];
        }

        if ($user->isAtRisk()) {
            return ['label' => 'A verifier', 'tone' => 'amber'];
        }

        return ['label' => 'Actif', 'tone' => 'green'];
    }

    /**
     * @return array<int, array{title:string,description:string,icon:string,tone:string}>
     */
    private function buildRecommendedActions(
        int $accountsToVerify,
        int $incompleteProfiles,
        int $securityAlerts,
        int $pendingDeactivationRequests
    ): array {
        $actions = [];

        if ($accountsToVerify > 0) {
            $actions[] = [
                'title' => 'Envoyer une relance KYC',
                'description' => $accountsToVerify . ' compte(s) demandent une verification ou une relecture admin.',
                'icon' => 'bi-send',
                'tone' => 'blue',
            ];
        }

        if ($incompleteProfiles > 0) {
            $actions[] = [
                'title' => 'Demander la mise a jour du profil',
                'description' => $incompleteProfiles . ' profil(s) restent partiels ou peu exploitables.',
                'icon' => 'bi-person-gear',
                'tone' => 'green',
            ];
        }

        if ($securityAlerts > 0) {
            $actions[] = [
                'title' => 'Activer une revue securite ciblee',
                'description' => $securityAlerts . ' signal(aux) elevé(s) meritent une vigilance renforcee.',
                'icon' => 'bi-shield-lock',
                'tone' => 'red',
            ];
        }

        if ($pendingDeactivationRequests > 0) {
            $actions[] = [
                'title' => 'Examiner les demandes de desactivation',
                'description' => $pendingDeactivationRequests . ' demande(s) attendent une decision admin.',
                'icon' => 'bi-file-earmark-check',
                'tone' => 'amber',
            ];
        }

        $actions[] = [
            'title' => 'Exporter le dossier utilisateur en PDF',
            'description' => 'Consolidez les comptes prioritaires pour un suivi rapide en comite.',
            'icon' => 'bi-file-earmark-pdf',
            'tone' => 'violet',
        ];

        return array_slice($actions, 0, 5);
    }

    /**
     * @param array<int, array<string, mixed>> $pendingDeactivationRequests
     * @param array<int, array<string, mixed>> $priorityUsers
     * @return array<int, array{title:string,description:string,time:string,tone:string,icon:string}>
     */
    private function buildPersonalizedNotifications(
        int $accountsToVerify,
        int $incompleteProfiles,
        int $securityAlerts,
        array $pendingDeactivationRequests,
        array $priorityUsers
    ): array {
        $notifications = [];

        if ($incompleteProfiles > 0) {
            $notifications[] = [
                'title' => 'Des profils restent incomplets',
                'description' => 'Veuillez completer ou faire completer les informations personnelles prioritaires.',
                'time' => 'Maintenant',
                'tone' => 'amber',
                'icon' => 'bi-exclamation-circle',
            ];
        }

        if ($securityAlerts > 0) {
            $notifications[] = [
                'title' => 'Vigilance securite recommandee',
                'description' => 'Des comptes sensibles demandent une double verification ou une revue humaine.',
                'time' => 'Il y a 1 h',
                'tone' => 'red',
                'icon' => 'bi-shield-exclamation',
            ];
        }

        if ($accountsToVerify > 0) {
            $notifications[] = [
                'title' => 'Comptes a verifier',
                'description' => 'Centralisez les utilisateurs en attente de validation, KYC ou activation.',
                'time' => 'Il y a 2 h',
                'tone' => 'blue',
                'icon' => 'bi-search',
            ];
        }

        if ($pendingDeactivationRequests !== []) {
            $notifications[] = [
                'title' => 'Demandes de desactivation en attente',
                'description' => 'Un arbitrage admin est conseille pour eviter une perte de confiance durable.',
                'time' => 'Il y a 3 h',
                'tone' => 'violet',
                'icon' => 'bi-person-x',
            ];
        }

        if ($priorityUsers !== []) {
            $topUser = $priorityUsers[0];
            /** @var User $user */
            $user = $topUser['user'];
            $notifications[] = [
                'title' => 'Utilisateur prioritaire : ' . $user->getFullName(),
                'description' => (string) ($topUser['nextAction']['reason'] ?? 'Une action contextualisee est suggeree sur ce compte.'),
                'time' => 'Aujourd hui',
                'tone' => 'green',
                'icon' => 'bi-stars',
            ];
        }

        return array_slice($notifications, 0, 5);
    }

    /**
     * @return array<int, array{label:string,date:string,icon:string,done:bool,tone:string}>
     */
    private function buildJourneySteps(User $user): array
    {
        $createdAt = \DateTimeImmutable::createFromInterface($user->getCreatedAt());
        $behaviorAt = $user->getBehaviorUpdatedAt() ? \DateTimeImmutable::createFromInterface($user->getBehaviorUpdatedAt()) : null;
        $verificationAt = $user->getEmailVerifiedAt() ? \DateTimeImmutable::createFromInterface($user->getEmailVerifiedAt()) : null;

        $kycSubmittedAt = $user->getKycStatus() !== null ? $createdAt->modify('+2 days') : null;
        $kycValidatedAt = $user->isKycApproved() ? ($verificationAt ?? $createdAt->modify('+4 days')) : null;
        $walletActivatedAt = $user->isActive() ? ($behaviorAt ?? $createdAt->modify('+5 days')) : null;
        $firstTransactionAt = $user->getTransactionFrequency() > 0 ? ($behaviorAt ?? $createdAt->modify('+7 days')) : null;

        return [
            [
                'label' => 'Compte cree',
                'date' => $createdAt->format('d/m/Y'),
                'icon' => 'bi-person',
                'done' => true,
                'tone' => 'blue',
            ],
            [
                'label' => 'KYC soumis',
                'date' => $kycSubmittedAt?->format('d/m/Y') ?? 'En attente',
                'icon' => 'bi-file-earmark-medical',
                'done' => $kycSubmittedAt !== null,
                'tone' => $kycSubmittedAt !== null ? 'green' : 'amber',
            ],
            [
                'label' => 'KYC valide',
                'date' => $kycValidatedAt?->format('d/m/Y') ?? 'A verifier',
                'icon' => 'bi-shield-check',
                'done' => $kycValidatedAt !== null,
                'tone' => $kycValidatedAt !== null ? 'green' : 'amber',
            ],
            [
                'label' => 'Wallet active',
                'date' => $walletActivatedAt?->format('d/m/Y') ?? 'En attente',
                'icon' => 'bi-wallet2',
                'done' => $walletActivatedAt !== null,
                'tone' => $walletActivatedAt !== null ? 'blue' : 'amber',
            ],
            [
                'label' => 'Premiere transaction',
                'date' => $firstTransactionAt?->format('d/m/Y') ?? 'Pas encore',
                'icon' => 'bi-credit-card',
                'done' => $firstTransactionAt !== null,
                'tone' => $firstTransactionAt !== null ? 'violet' : 'amber',
            ],
        ];
    }
}
