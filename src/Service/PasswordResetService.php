<?php

namespace App\Service;

use App\Entity\User\Client\PasswordResetAuditLog;
use App\Entity\User\Client\PasswordResetRequest;
use App\Entity\User\User;
use App\Repository\PasswordResetAuditLogRepository;
use App\Repository\PasswordResetRequestRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class PasswordResetService
{
    public const SESSION_AUTH_KEY = 'password_reset.authorized';

    private const EMAIL_EXPIRY_MINUTES = 15;
    private const MAX_REQUESTS_PER_IDENTIFIER = 5;
    private const MAX_REQUESTS_PER_IP = 15;
    private const REQUEST_WINDOW_MINUTES = 15;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserRepository $userRepository,
        private readonly PasswordResetRequestRepository $passwordResetRequestRepository,
        private readonly PasswordResetAuditLogRepository $passwordResetAuditLogRepository,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly PasswordResetMailer $passwordResetMailer,
        private readonly string $appSecret,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly string $kernelEnvironment,
    ) {}

    /**
     * @return array{identifier:string,recoveryHash:string,userId:int|null,maskedEmail:string,throttled:bool}
     */
    public function initiateResetFlow(string $identifier, Request $request): array
    {
        $normalizedIdentifier = $this->normalizeIdentifier($identifier);
        $recoveryHash = $this->hashRecoveryIdentifier($normalizedIdentifier);
        $requestIp = $this->resolveRequestIp($request);

        $this->logEvent(
            null,
            PasswordResetAuditLog::EVENT_REQUEST_RECEIVED,
            $recoveryHash,
            null,
            $request,
            ['identifier_masked' => $this->maskEmail($normalizedIdentifier)]
        );

        $throttled = $this->isRequestRateLimited($recoveryHash, $requestIp);
        $user = filter_var($normalizedIdentifier, FILTER_VALIDATE_EMAIL)
            ? $this->userRepository->findByEmail($normalizedIdentifier)
            : null;

        return [
            'identifier' => $normalizedIdentifier,
            'recoveryHash' => $recoveryHash,
            'userId' => $user?->getId(),
            'maskedEmail' => $this->maskEmail($user?->getEmail() ?? $normalizedIdentifier),
            'throttled' => $throttled,
        ];
    }

    /**
     * @return array{sent:bool,request:?PasswordResetRequest,debug_reset_url:?string}
     */
    public function dispatchEmail(array $flow, Request $request): array
    {
        $requestIp = $this->resolveRequestIp($request);
        $channel = PasswordResetRequest::CHANNEL_EMAIL;

        if (($flow['throttled'] ?? false) === true || $this->isRequestRateLimited($flow['recoveryHash'], $requestIp)) {
            $this->logEvent(
                null,
                PasswordResetAuditLog::EVENT_RATE_LIMITED,
                $flow['recoveryHash'],
                $channel,
                $request,
                ['reason' => 'request_window_limit']
            );

            return [
                'sent' => false,
                'request' => null,
                'debug_reset_url' => null,
            ];
        }

        $user = $this->getFlowUser($flow);
        if (!$user instanceof User) {
            $this->logEvent(
                null,
                PasswordResetAuditLog::EVENT_CHANNEL_DISPATCHED,
                $flow['recoveryHash'],
                $channel,
                $request,
                ['delivery' => 'generic_no_user']
            );

            return [
                'sent' => false,
                'request' => null,
                'debug_reset_url' => null,
            ];
        }

        $this->invalidateActiveRequestsForUser($user);

        $passwordResetRequest = (new PasswordResetRequest())
            ->setPublicId(bin2hex(random_bytes(16)))
            ->setUser($user)
            ->setRecoveryHash($flow['recoveryHash'])
            ->setChannel($channel)
            ->setRequestIp($requestIp)
            ->setUserAgent($request->headers->get('User-Agent'))
            ->setStatus(PasswordResetRequest::STATUS_PENDING)
            ->setLastSentAt(new \DateTime());

        $secret = bin2hex(random_bytes(32));
        $passwordResetRequest->setExpiresAt((new \DateTime())->modify('+' . self::EMAIL_EXPIRY_MINUTES . ' minutes'));

        $passwordResetRequest->setSecretHash($this->hashSecret($secret));
        $this->entityManager->persist($passwordResetRequest);
        $this->entityManager->flush();

        $debugResetUrl = $this->kernelEnvironment === 'dev'
            ? $this->urlGenerator->generate('app_password_reset_email_verify', [
                'publicId' => $passwordResetRequest->getPublicId(),
                'token' => $secret,
            ], UrlGeneratorInterface::ABSOLUTE_URL)
            : null;

        try {
            $this->sendThroughChannel($passwordResetRequest, $user, $secret);
        } catch (\Throwable $exception) {
            $passwordResetRequest->cancel();
            $this->entityManager->flush();
            $this->logEvent(
                $passwordResetRequest,
                PasswordResetAuditLog::EVENT_CHANNEL_DISPATCHED,
                $flow['recoveryHash'],
                $channel,
                $request,
                ['delivery' => 'failed', 'message' => $exception->getMessage()]
            );

            return [
                'sent' => false,
                'request' => $passwordResetRequest,
                'debug_reset_url' => $debugResetUrl,
            ];
        }

        $this->logEvent(
            $passwordResetRequest,
            PasswordResetAuditLog::EVENT_CHANNEL_DISPATCHED,
            $flow['recoveryHash'],
            $channel,
            $request,
            ['delivery' => 'sent']
        );

        return [
            'sent' => true,
            'request' => $passwordResetRequest,
            'debug_reset_url' => null,
        ];
    }

    public function verifyEmailToken(string $publicId, string $token, Request $request): ?PasswordResetRequest
    {
        $passwordResetRequest = $this->passwordResetRequestRepository->findOneByPublicId($publicId);
        if (!$passwordResetRequest instanceof PasswordResetRequest) {
            return null;
        }

        if ($passwordResetRequest->isExpired()) {
            $passwordResetRequest->markExpired();
            $this->entityManager->flush();
            $this->logEvent(
                $passwordResetRequest,
                PasswordResetAuditLog::EVENT_EXPIRED,
                $passwordResetRequest->getRecoveryHash(),
                $passwordResetRequest->getChannel(),
                $request
            );

            return null;
        }

        if ($passwordResetRequest->getChannel() !== PasswordResetRequest::CHANNEL_EMAIL) {
            return null;
        }

        if (!hash_equals((string) $passwordResetRequest->getSecretHash(), $this->hashSecret($token))) {
            $this->logEvent(
                $passwordResetRequest,
                PasswordResetAuditLog::EVENT_TOKEN_INVALID,
                $passwordResetRequest->getRecoveryHash(),
                $passwordResetRequest->getChannel(),
                $request
            );

            return null;
        }

        $passwordResetRequest->markVerified();
        $this->entityManager->flush();

        $this->logEvent(
            $passwordResetRequest,
            PasswordResetAuditLog::EVENT_LINK_VERIFIED,
            $passwordResetRequest->getRecoveryHash(),
            $passwordResetRequest->getChannel(),
            $request
        );

        return $passwordResetRequest;
    }

    public function getAuthorizedResetRequest(string $publicId): ?PasswordResetRequest
    {
        $passwordResetRequest = $this->passwordResetRequestRepository->findOneByPublicId($publicId);
        if (!$passwordResetRequest instanceof PasswordResetRequest) {
            return null;
        }

        if ($passwordResetRequest->isExpired()) {
            $passwordResetRequest->markExpired();
            $this->entityManager->flush();

            return null;
        }

        if (!$passwordResetRequest->isVerified()) {
            return null;
        }

        return $passwordResetRequest;
    }

    public function resetPassword(PasswordResetRequest $passwordResetRequest, string $plainPassword, Request $request): void
    {
        $user = $passwordResetRequest->getUser();
        if (!$user instanceof User) {
            throw new \RuntimeException('Impossible de reinitialiser le mot de passe pour cette demande.');
        }

        $user
            ->setPassword($this->passwordHasher->hashPassword($user, $plainPassword))
            ->setPasswordChangedAt(new \DateTime())
            ->rotateAuthSessionVersion();

        $passwordResetRequest->markUsed();
        $this->invalidateActiveRequestsForUser($user, $passwordResetRequest);
        $this->entityManager->flush();

        $this->logEvent(
            $passwordResetRequest,
            PasswordResetAuditLog::EVENT_PASSWORD_CHANGED,
            $passwordResetRequest->getRecoveryHash(),
            $passwordResetRequest->getChannel(),
            $request
        );
    }

    public function getFlowUser(array $flow): ?User
    {
        $userId = $flow['userId'] ?? null;
        if (!is_int($userId) || $userId <= 0) {
            return null;
        }

        $user = $this->userRepository->find($userId);

        return $user instanceof User ? $user : null;
    }

    public function maskEmail(?string $email): string
    {
        if ($email === null || !str_contains($email, '@')) {
            return 'm***@fintrust.tn';
        }

        [$localPart, $domain] = explode('@', $email, 2);

        return mb_substr($localPart, 0, 1) . '***@' . $domain;
    }

    private function sendThroughChannel(PasswordResetRequest $passwordResetRequest, User $user, string $secret): void
    {
        $this->passwordResetMailer->sendResetLink($user, $passwordResetRequest, $secret);
    }

    private function invalidateActiveRequestsForUser(User $user, ?PasswordResetRequest $except = null): void
    {
        foreach ($this->passwordResetRequestRepository->findPendingByUser($user) as $pendingRequest) {
            if ($except instanceof PasswordResetRequest && $pendingRequest->getId() === $except->getId()) {
                continue;
            }

            $pendingRequest->cancel();
        }
    }

    private function isRequestRateLimited(string $recoveryHash, ?string $requestIp): bool
    {
        $since = (new \DateTime())->modify('-' . self::REQUEST_WINDOW_MINUTES . ' minutes');

        if ($this->passwordResetAuditLogRepository->countRecentRequestsForRecoveryHash($recoveryHash, $since) >= self::MAX_REQUESTS_PER_IDENTIFIER) {
            return true;
        }

        return $requestIp !== null
            && $requestIp !== ''
            && $this->passwordResetAuditLogRepository->countRecentRequestsForIp($requestIp, $since) >= self::MAX_REQUESTS_PER_IP;
    }

    private function hashSecret(string $secret): string
    {
        return hash_hmac('sha256', $secret, $this->appSecret);
    }

    private function hashRecoveryIdentifier(string $identifier): string
    {
        return hash('sha256', $identifier);
    }

    private function normalizeIdentifier(string $identifier): string
    {
        return mb_strtolower(trim($identifier));
    }

    private function resolveRequestIp(Request $request): ?string
    {
        $ip = $request->getClientIp();

        return is_string($ip) && $ip !== '' ? $ip : null;
    }

    /**
     * @param array<string, mixed> $context
     */
    private function logEvent(
        ?PasswordResetRequest $passwordResetRequest,
        string $eventType,
        ?string $recoveryHash,
        ?string $channel,
        Request $request,
        array $context = []
    ): void {
        $auditLog = (new PasswordResetAuditLog())
            ->setPasswordResetRequest($passwordResetRequest)
            ->setEventType($eventType)
            ->setRecoveryHash($recoveryHash)
            ->setChannel($channel)
            ->setRequestIp($this->resolveRequestIp($request))
            ->setUserAgent($request->headers->get('User-Agent'))
            ->setContext($context === [] ? null : json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $this->entityManager->persist($auditLog);
        $this->entityManager->flush();
    }
}
