<?php

namespace App\Service;

use App\Entity\User\User;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

class BiometricPasskeyService
{
    private const SESSION_ENROLL_KEY = 'biometric.enroll';
    private const SESSION_AUTH_KEY = 'biometric.auth';

    public function __construct(
        private readonly string $projectDir,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function createEnrollmentOptions(User $user, string $rpId, SessionInterface $session): array
    {
        $challenge = $this->generateChallenge();
        $session->set(self::SESSION_ENROLL_KEY, [
            'challenge' => $challenge,
            'userId' => $user->getId(),
            'rpId' => $rpId,
        ]);

        return [
            'challenge' => $challenge,
            'rp' => [
                'name' => 'FinTrust',
                'id' => $rpId,
            ],
            'user' => [
                'id' => $this->base64UrlEncode((string) $user->getId()),
                'name' => $user->getEmail(),
                'displayName' => $user->getFullName(),
            ],
            'pubKeyCredParams' => [
                ['type' => 'public-key', 'alg' => -7],
                ['type' => 'public-key', 'alg' => -257],
            ],
            'authenticatorSelection' => [
                'authenticatorAttachment' => 'platform',
                'residentKey' => 'preferred',
                'userVerification' => 'required',
            ],
            'attestation' => 'none',
            'timeout' => 60000,
            'excludeCredentials' => array_map(
                fn (array $credential): array => [
                    'type' => 'public-key',
                    'id' => $credential['credentialId'],
                ],
                $this->readCredentials($user)
            ),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function completeEnrollment(User $user, array $payload, string $origin, SessionInterface $session): void
    {
        $state = $session->get(self::SESSION_ENROLL_KEY);
        $session->remove(self::SESSION_ENROLL_KEY);

        if (!is_array($state) || ($state['userId'] ?? null) !== $user->getId()) {
            throw new \RuntimeException('Le challenge d activation a expire. Veuillez recommencer.');
        }

        $credentialId = (string) ($payload['id'] ?? '');
        $publicKeySpki = (string) ($payload['publicKeySpki'] ?? '');
        $algorithm = (int) ($payload['publicKeyAlgorithm'] ?? 0);
        $clientData = $this->decodeJsonBase64((string) ($payload['clientDataJSON'] ?? ''));

        if ($credentialId === '' || $publicKeySpki === '' || $algorithm === 0) {
            throw new \RuntimeException('Le navigateur n a pas fourni de passkey exploitable.');
        }

        if (($clientData['type'] ?? null) !== 'webauthn.create') {
            throw new \RuntimeException('La reponse d activation biométrique est invalide.');
        }

        if (($clientData['challenge'] ?? null) !== ($state['challenge'] ?? null)) {
            throw new \RuntimeException('Le challenge biométrique ne correspond pas.');
        }

        if (($clientData['origin'] ?? null) !== $origin) {
            throw new \RuntimeException('L origine de la demande biométrique est invalide.');
        }

        $credentials = $this->readCredentials($user);

        foreach ($credentials as $credential) {
            if (($credential['credentialId'] ?? null) === $credentialId) {
                throw new \RuntimeException('Cet appareil est deja active pour ce compte.');
            }
        }

        $credentials[] = [
            'credentialId' => $credentialId,
            'publicKeyPem' => $this->spkiToPem($publicKeySpki),
            'algorithm' => $algorithm,
            'label' => (string) ($payload['deviceLabel'] ?? 'Appareil biométrique'),
            'createdAt' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ];

        $this->writeCredentials($user, $credentials);
    }

    /**
     * @return array<string, mixed>
     */
    public function createAuthenticationOptions(User $user, string $rpId, SessionInterface $session): array
    {
        $credentials = $this->readCredentials($user);
        if ($credentials === []) {
            throw new \RuntimeException('Aucun appareil biométrique n est enregistre pour ce compte.');
        }

        $challenge = $this->generateChallenge();
        $session->set(self::SESSION_AUTH_KEY, [
            'challenge' => $challenge,
            'userId' => $user->getId(),
            'rpId' => $rpId,
        ]);

        return [
            'challenge' => $challenge,
            'rpId' => $rpId,
            'timeout' => 60000,
            'userVerification' => 'required',
            'allowCredentials' => array_map(
                fn (array $credential): array => [
                    'type' => 'public-key',
                    'id' => $credential['credentialId'],
                ],
                $credentials
            ),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function verifyAuthentication(User $user, array $payload, string $origin, SessionInterface $session): void
    {
        $state = $session->get(self::SESSION_AUTH_KEY);
        $session->remove(self::SESSION_AUTH_KEY);

        if (!is_array($state) || ($state['userId'] ?? null) !== $user->getId()) {
            throw new \RuntimeException('La tentative biométrique a expire. Veuillez recommencer.');
        }

        $credentialId = (string) ($payload['id'] ?? '');
        $clientDataJson = (string) ($payload['clientDataJSON'] ?? '');
        $authenticatorData = (string) ($payload['authenticatorData'] ?? '');
        $signature = (string) ($payload['signature'] ?? '');

        if ($credentialId === '' || $clientDataJson === '' || $authenticatorData === '' || $signature === '') {
            throw new \RuntimeException('La preuve biométrique est incomplete.');
        }

        $clientData = $this->decodeJsonBase64($clientDataJson);
        if (($clientData['type'] ?? null) !== 'webauthn.get') {
            throw new \RuntimeException('La reponse de connexion biométrique est invalide.');
        }

        if (($clientData['challenge'] ?? null) !== ($state['challenge'] ?? null)) {
            throw new \RuntimeException('Le challenge de connexion biométrique ne correspond pas.');
        }

        if (($clientData['origin'] ?? null) !== $origin) {
            throw new \RuntimeException('L origine de la connexion biométrique est invalide.');
        }

        $credential = $this->findCredential($user, $credentialId);
        if ($credential === null) {
            throw new \RuntimeException('Cet appareil n est pas autorise pour ce compte.');
        }

        $authenticatorDataBinary = $this->base64UrlDecode($authenticatorData);
        if (strlen($authenticatorDataBinary) < 37) {
            throw new \RuntimeException('Les donnees d authentification biométrique sont invalides.');
        }

        $expectedRpIdHash = hash('sha256', (string) $state['rpId'], true);
        $rpIdHash = substr($authenticatorDataBinary, 0, 32);
        if (!hash_equals($expectedRpIdHash, $rpIdHash)) {
            throw new \RuntimeException('La preuve biométrique n appartient pas a ce domaine.');
        }

        $flags = ord($authenticatorDataBinary[32]);
        $userPresent = (bool) ($flags & 0x01);
        $userVerified = (bool) ($flags & 0x04);
        if (!$userPresent || !$userVerified) {
            throw new \RuntimeException('La verification biométrique de l appareil n a pas ete confirmee.');
        }

        $clientDataHash = hash('sha256', $this->base64UrlDecode($clientDataJson), true);
        $signedData = $authenticatorDataBinary . $clientDataHash;
        $signatureBinary = $this->base64UrlDecode($signature);
        $algorithm = $this->opensslAlgorithm((int) ($credential['algorithm'] ?? -7));

        $verified = openssl_verify($signedData, $signatureBinary, (string) $credential['publicKeyPem'], $algorithm);
        if ($verified !== 1) {
            throw new \RuntimeException('Cette identité biométrique ne correspond pas au compte saisi.');
        }
    }

    public function hasCredentials(User $user): bool
    {
        return $this->readCredentials($user) !== [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function readCredentials(User $user): array
    {
        $path = $this->getUserFilePath($user);
        if (!is_file($path)) {
            return [];
        }

        $json = file_get_contents($path);
        $data = json_decode($json ?: '[]', true);

        return is_array($data) ? $data : [];
    }

    /**
     * @param array<int, array<string, mixed>> $credentials
     */
    private function writeCredentials(User $user, array $credentials): void
    {
        $directory = dirname($this->getUserFilePath($user));
        if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new \RuntimeException('Impossible de preparer le stockage biométrique.');
        }

        file_put_contents(
            $this->getUserFilePath($user),
            json_encode($credentials, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findCredential(User $user, string $credentialId): ?array
    {
        foreach ($this->readCredentials($user) as $credential) {
            if (($credential['credentialId'] ?? null) === $credentialId) {
                return $credential;
            }
        }

        return null;
    }

    private function getUserFilePath(User $user): string
    {
        return $this->projectDir . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'biometric' . DIRECTORY_SEPARATOR . 'user_' . $user->getId() . '.json';
    }

    private function generateChallenge(): string
    {
        return $this->base64UrlEncode(random_bytes(32));
    }

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $data): string
    {
        $remainder = strlen($data) % 4;
        if ($remainder > 0) {
            $data .= str_repeat('=', 4 - $remainder);
        }

        $decoded = base64_decode(strtr($data, '-_', '+/'), true);
        if ($decoded === false) {
            throw new \RuntimeException('La charge biométrique recue n est pas valide.');
        }

        return $decoded;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJsonBase64(string $encoded): array
    {
        $decoded = $this->base64UrlDecode($encoded);
        $json = json_decode($decoded, true);

        if (!is_array($json)) {
            throw new \RuntimeException('La reponse biométrique JSON est invalide.');
        }

        return $json;
    }

    private function spkiToPem(string $spkiBase64Url): string
    {
        $spki = $this->base64UrlDecode($spkiBase64Url);
        $pemBody = chunk_split(base64_encode($spki), 64, PHP_EOL);

        return "-----BEGIN PUBLIC KEY-----" . PHP_EOL . $pemBody . "-----END PUBLIC KEY-----" . PHP_EOL;
    }

    private function opensslAlgorithm(int $algorithm): int|string
    {
        return match ($algorithm) {
            -7, -257 => OPENSSL_ALGO_SHA256,
            -35, -258 => OPENSSL_ALGO_SHA384,
            -36, -259 => OPENSSL_ALGO_SHA512,
            default => OPENSSL_ALGO_SHA256,
        };
    }
}
