<?php

namespace App\Service;

use Symfony\Component\HttpFoundation\Session\SessionInterface;

class CaptchaService
{
    private const LOGIN_FAILURE_KEY = '_ft_login_failures';
    private const LOGIN_THRESHOLD = 3;
    private const MIN_SOLVE_SECONDS = 1;

    public function requiresLoginCaptcha(SessionInterface $session): bool
    {
        return $this->getLoginFailures($session) >= self::LOGIN_THRESHOLD;
    }

    public function getLoginFailures(SessionInterface $session): int
    {
        return max(0, (int) $session->get(self::LOGIN_FAILURE_KEY, 0));
    }

    public function incrementLoginFailures(SessionInterface $session): void
    {
        $session->set(self::LOGIN_FAILURE_KEY, $this->getLoginFailures($session) + 1);
    }

    public function resetLoginFailures(SessionInterface $session): void
    {
        $session->remove(self::LOGIN_FAILURE_KEY);
    }

    public function getOrCreateChallenge(SessionInterface $session, string $context): array
    {
        $key = $this->getChallengeKey($context);
        $challenge = $session->get($key);

        if (!is_array($challenge) || !isset($challenge['token'], $challenge['label'], $challenge['issuedAt'])) {
            $challenge = $this->generateChallenge();
            $session->set($key, $challenge);
        }

        return $challenge;
    }

    public function refreshChallenge(SessionInterface $session, string $context): array
    {
        $challenge = $this->generateChallenge();
        $session->set($this->getChallengeKey($context), $challenge);

        return $challenge;
    }

    public function clearChallenge(SessionInterface $session, string $context): void
    {
        $session->remove($this->getChallengeKey($context));
    }

    public function validateAnswer(SessionInterface $session, string $context, string|null $token, bool $confirmed): bool
    {
        $challenge = $session->get($this->getChallengeKey($context));

        if (!is_array($challenge) || !isset($challenge['token'], $challenge['issuedAt'])) {
            return false;
        }

        if (!$confirmed) {
            return false;
        }

        if (!hash_equals((string) $challenge['token'], trim((string) $token))) {
            return false;
        }

        return (time() - (int) $challenge['issuedAt']) >= self::MIN_SOLVE_SECONDS;
    }

    private function getChallengeKey(string $context): string
    {
        return '_ft_captcha_' . $context;
    }

    /**
     * @return array{label:string, token:string, issuedAt:int}
     */
    private function generateChallenge(): array
    {
        return [
            'label' => 'Je ne suis pas un robot',
            'token' => bin2hex(random_bytes(16)),
            'issuedAt' => time(),
        ];
    }
}
