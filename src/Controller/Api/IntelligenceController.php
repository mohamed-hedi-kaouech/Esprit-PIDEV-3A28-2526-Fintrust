<?php

namespace App\Controller\Api;

use App\Entity\User\User;
use App\Repository\UserRepository;
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
