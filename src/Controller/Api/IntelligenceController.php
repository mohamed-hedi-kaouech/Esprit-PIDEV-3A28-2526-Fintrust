<?php

namespace App\Controller\Api;

use App\Entity\User\User;
use App\Repository\UserRepository;
use App\Service\AdvancedAnalyticsService;
use App\Service\ComplianceCopilotService;
use App\Service\KycVerificationCenterService;
use App\Service\UserIntelligenceService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route('', name: 'api_')]
class IntelligenceController extends AbstractController
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly UserIntelligenceService $userIntelligenceService,
        private readonly KycVerificationCenterService $kycVerificationCenterService,
        private readonly ComplianceCopilotService $complianceCopilotService,
        private readonly AdvancedAnalyticsService $advancedAnalyticsService,
        private readonly ValidatorInterface $validator,
    ) {}

    #[Route('/users/{id}/profile-enrichment', name: 'user_profile_enrichment', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function profileEnrichment(int $id, Request $request): JsonResponse
    {
        $user = $this->getAccessibleUser($id);
        if (!$user instanceof User) {
            return $user;
        }

        $payload = $this->parseJsonRequest($request);
        $validation = $this->validateProfilePayload($payload);
        if ($validation instanceof JsonResponse) {
            return $validation;
        }

        return $this->json($this->userIntelligenceService->buildProfileEnrichment($user, $payload));
    }

    #[Route('/users/{id}/risk-profile', name: 'user_risk_profile', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function riskProfile(int $id): JsonResponse
    {
        $user = $this->getAccessibleUser($id);
        if (!$user instanceof User) {
            return $user;
        }

        return $this->json($this->userIntelligenceService->getRiskProfile($user));
    }

    #[Route('/users/{id}/financial-behavior-summary', name: 'user_financial_behavior', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function financialBehaviorSummary(int $id): JsonResponse
    {
        $user = $this->getAccessibleUser($id);
        if (!$user instanceof User) {
            return $user;
        }

        return $this->json($this->userIntelligenceService->getFinancialBehaviorSummary($user));
    }

    #[Route('/kyc/verify-identity', name: 'kyc_verify_identity', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function verifyIdentity(Request $request): JsonResponse
    {
        $payload = $this->parseJsonRequest($request);
        $userId = (int) ($payload['userId'] ?? 0);
        $user = $this->getAccessibleUser($userId);
        if (!$user instanceof User) {
            return $user;
        }

        return $this->json($this->kycVerificationCenterService->verifyIdentity($user));
    }

    #[Route('/kyc/verify-document', name: 'kyc_verify_document', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function verifyDocument(Request $request): JsonResponse
    {
        $payload = $this->parseJsonRequest($request);
        $errors = $this->validator->validate($payload, new Assert\Collection([
            'userId' => [new Assert\NotBlank()],
            'documentType' => [new Assert\NotBlank()],
            'documentImageUrl' => [new Assert\NotBlank()],
        ]));

        if (count($errors) > 0) {
            return $this->json(['message' => (string) $errors[0]->getMessage()], 422);
        }

        return $this->json($this->kycVerificationCenterService->verifyDocumentFromPayload($payload));
    }

    #[Route('/kyc/selfie-match', name: 'kyc_selfie_match', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function selfieMatch(Request $request): JsonResponse
    {
        $payload = $this->parseJsonRequest($request);
        $errors = $this->validator->validate($payload, new Assert\Collection([
            'userId' => [new Assert\NotBlank()],
            'selfieImageUrl' => [new Assert\NotBlank()],
            'documentFaceImageUrl' => [new Assert\NotBlank()],
        ]));

        if (count($errors) > 0) {
            return $this->json(['message' => (string) $errors[0]->getMessage()], 422);
        }

        return $this->json($this->kycVerificationCenterService->verifySelfieMatchFromPayload($payload));
    }

    #[Route('/kyc/status/{userId}', name: 'kyc_status', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function kycStatus(int $userId): JsonResponse
    {
        $user = $this->getAccessibleUser($userId);
        if (!$user instanceof User) {
            return $user;
        }

        return $this->json($this->kycVerificationCenterService->buildCenter($user));
    }

    #[Route('/compliance/generate-review', name: 'compliance_generate_review', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function generateComplianceReview(Request $request): JsonResponse
    {
        $payload = $this->parseJsonRequest($request);
        $validation = $this->validateCompliancePayload($payload);
        if ($validation instanceof JsonResponse) {
            return $validation;
        }

        $user = null;
        $userId = isset($payload['userId']) ? (int) $payload['userId'] : 0;
        if ($userId > 0) {
            $user = $this->userRepository->find($userId);
            if (!$user instanceof User) {
                return $this->json(['message' => 'Utilisateur introuvable.'], 404);
            }
        }

        return $this->json($this->complianceCopilotService->generateReview($user, $payload));
    }

    #[Route('/users/{id}/news-relevance', name: 'user_news_relevance', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function newsRelevance(int $id): JsonResponse
    {
        $user = $this->getAccessibleUser($id);
        if (!$user instanceof User) {
            return $user;
        }

        return $this->json($this->advancedAnalyticsService->getNewsRelevance($user));
    }

    #[Route('/news/score-impact', name: 'news_score_impact', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function scoreImpact(Request $request): JsonResponse
    {
        return $this->json($this->advancedAnalyticsService->scoreNewsImpact($this->parseJsonRequest($request)));
    }

    #[Route('/news/personalized-feed', name: 'news_personalized_feed', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function personalizedFeed(): JsonResponse
    {
        /** @var User|null $actor */
        $actor = $this->getUser();
        if (!$actor instanceof User) {
            return $this->json(['message' => 'Utilisateur non authentifie.'], 401);
        }

        return $this->json($this->advancedAnalyticsService->getNewsRelevance($actor));
    }

    #[Route('/users/{id}/next-best-action', name: 'user_next_best_action', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function nextBestAction(int $id): JsonResponse
    {
        $user = $this->getAccessibleUser($id);
        if (!$user instanceof User) {
            return $user;
        }

        return $this->json($this->advancedAnalyticsService->getNextBestAction($user));
    }

    #[Route('/recommendations/generate', name: 'recommendations_generate', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function generateRecommendation(Request $request): JsonResponse
    {
        $payload = $this->parseJsonRequest($request);
        $userId = (int) ($payload['userId'] ?? 0);
        $user = $this->getAccessibleUser($userId);
        if (!$user instanceof User) {
            return $user;
        }

        return $this->json($this->advancedAnalyticsService->getNextBestAction($user));
    }

    #[Route('/users/{id}/action-priority', name: 'user_action_priority', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function actionPriority(int $id): JsonResponse
    {
        $user = $this->getAccessibleUser($id);
        if (!$user instanceof User) {
            return $user;
        }

        return $this->json($this->advancedAnalyticsService->getActionPrioritySummary($user));
    }

    #[Route('/users/{id}/dropoff-risk', name: 'user_dropoff_risk', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function dropoffRisk(int $id): JsonResponse
    {
        $user = $this->getAccessibleUser($id);
        if (!$user instanceof User) {
            return $user;
        }

        return $this->json($this->advancedAnalyticsService->getDropoffRisk($user));
    }

    #[Route('/users/{id}/engagement-analysis', name: 'user_engagement_analysis', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function engagementAnalysis(int $id, Request $request): JsonResponse
    {
        $user = $this->getAccessibleUser($id);
        if (!$user instanceof User) {
            return $user;
        }

        return $this->json($this->advancedAnalyticsService->getEngagementAnalysis($user, $this->parseJsonRequest($request)));
    }

    #[Route('/retention/at-risk-users', name: 'retention_at_risk_users', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function atRiskUsers(): JsonResponse
    {
        $clients = $this->userRepository->findBy(['role' => 'CLIENT'], ['createdAt' => 'DESC'], 100);

        return $this->json($this->advancedAnalyticsService->getAtRiskUsers($clients, 12));
    }

    #[Route('/identity/consistency-check', name: 'identity_consistency_check', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function identityConsistencyCheck(Request $request): JsonResponse
    {
        $payload = $this->parseJsonRequest($request);
        $userId = (int) ($payload['userId'] ?? 0);
        $user = $this->getAccessibleUser($userId);
        if (!$user instanceof User) {
            return $user;
        }

        return $this->json($this->advancedAnalyticsService->getIdentityConsistency($user));
    }

    #[Route('/users/{id}/identity-score', name: 'user_identity_score', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function identityScore(int $id): JsonResponse
    {
        $user = $this->getAccessibleUser($id);
        if (!$user instanceof User) {
            return $user;
        }

        return $this->json($this->advancedAnalyticsService->getIdentityConsistency($user));
    }

    #[Route('/analytics/admin-overview', name: 'analytics_admin_overview', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function analyticsAdminOverview(): JsonResponse
    {
        $clients = $this->userRepository->findBy(['role' => 'CLIENT'], ['createdAt' => 'DESC'], 120);

        return $this->json($this->advancedAnalyticsService->getAdminAnalyticsOverview($clients));
    }

    #[Route('/analytics/kyc-trends', name: 'analytics_kyc_trends', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function analyticsKycTrends(): JsonResponse
    {
        return $this->json($this->advancedAnalyticsService->getKycTrends());
    }

    #[Route('/analytics/risk-patterns', name: 'analytics_risk_patterns', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function analyticsRiskPatterns(): JsonResponse
    {
        $clients = $this->userRepository->findBy(['role' => 'CLIENT'], ['createdAt' => 'DESC'], 120);

        return $this->json($this->advancedAnalyticsService->getRiskPatterns($clients));
    }

    #[Route('/analytics/support-insights', name: 'analytics_support_insights', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function analyticsSupportInsights(): JsonResponse
    {
        return $this->json($this->advancedAnalyticsService->getSupportInsights());
    }

    /**
     * @return array<string, mixed>
     */
    private function parseJsonRequest(Request $request): array
    {
        try {
            $payload = $request->toArray();
        } catch (\Throwable) {
            $payload = [];
        }

        return is_array($payload) ? $payload : [];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function validateProfilePayload(array $payload): ?JsonResponse
    {
        $errors = $this->validator->validate($payload, new Assert\Collection([
            'allowExtraFields' => true,
            'allowMissingFields' => true,
            'transactionsCount30d' => new Assert\Optional([new Assert\Type('numeric')]),
            'avgAmount30d' => new Assert\Optional([new Assert\Type('numeric')]),
            'activityChangeRate' => new Assert\Optional([new Assert\Type('numeric')]),
            'latePayments' => new Assert\Optional([new Assert\Type('numeric')]),
            'deviceChanges' => new Assert\Optional([new Assert\Type('numeric')]),
            'suspiciousFlags' => new Assert\Optional([new Assert\Type('numeric')]),
        ]));

        if (count($errors) > 0) {
            return $this->json(['message' => (string) $errors[0]->getMessage()], 422);
        }

        return null;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function validateCompliancePayload(array $payload): ?JsonResponse
    {
        $errors = $this->validator->validate($payload, new Assert\Collection([
            'allowExtraFields' => true,
            'allowMissingFields' => true,
            'userId' => new Assert\Optional([new Assert\Type('numeric')]),
            'statutKyc' => new Assert\Optional([new Assert\Type('string')]),
            'niveauRisque' => new Assert\Optional([new Assert\Type('string')]),
            'profilClient' => new Assert\Optional([new Assert\Type('array')]),
            'anomaliesDocumentaires' => new Assert\Optional([new Assert\Type('array')]),
            'signauxTransactionnels' => new Assert\Optional([new Assert\Type('array')]),
            'alertesExistantes' => new Assert\Optional([new Assert\Type('array')]),
            'historiqueRecent' => new Assert\Optional([new Assert\Type('array')]),
        ]));

        if (count($errors) > 0) {
            return $this->json(['message' => (string) $errors[0]->getMessage()], 422);
        }

        return null;
    }

    private function getAccessibleUser(int $id): User|JsonResponse
    {
        /** @var User|null $actor */
        $actor = $this->getUser();
        $user = $this->userRepository->find($id);

        if (!$user) {
            return $this->json(['message' => 'Utilisateur introuvable.'], 404);
        }

        if (!$actor instanceof User) {
            return $this->json(['message' => 'Utilisateur non authentifie.'], 401);
        }

        if (!$actor->isAdmin() && $actor->getId() !== $user->getId()) {
            return $this->json(['message' => 'Acces refuse.'], 403);
        }

        return $user;
    }
}
