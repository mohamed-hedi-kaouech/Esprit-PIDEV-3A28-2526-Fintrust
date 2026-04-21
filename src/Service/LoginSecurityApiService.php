<?php

namespace App\Service;

use App\Entity\User\User;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class LoginSecurityApiService
{
    private const LOGIN_CONTEXT_DIR = 'security-login';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly NotificationService $notificationService,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
        #[Autowire('%env(bool:SECURITY_EXTERNAL_APIS_ENABLED)%')]
        private readonly bool $externalApisEnabled = false,
        #[Autowire('%env(default::SECURITY_TEST_IP)%')]
        private readonly ?string $securityTestIp = null,
    ) {
    }

    public function inspectSuccessfulLogin(User $user, Request $request): void
    {
        if (!$this->externalApisEnabled) {
            return;
        }

        $ip = $this->resolveClientIp($request);
        $geo = $this->lookupIp($ip);
        $previous = $this->readPreviousContext($user);
        $messages = [];

        if ($previous === null) {
            $messages[] = sprintf(
                'Connexion securisee avec localisation IP approximative : %s, %s via %s. IP analysee : %s. Une localisation navigateur peut confirmer une zone plus precise.',
                $geo['city'] ?: 'ville inconnue',
                $geo['country'] ?: 'pays inconnu',
                $geo['isp'] ?: 'fournisseur inconnu',
                $geo['ip']
            );
        }

        if ($this->isUnusualLocation($previous, $geo)) {
            $messages[] = sprintf(
                'Connexion inhabituelle detectee par localisation IP approximative : %s, %s via %s. IP analysee : %s. Si ce n etait pas vous, changez votre mot de passe.',
                $geo['city'] ?: 'ville inconnue',
                $geo['country'] ?: 'pays inconnu',
                $geo['isp'] ?: 'fournisseur inconnu',
                $geo['ip']
            );
        }

        foreach ($messages as $message) {
            $this->notificationService->notify($user, $message, 'WARNING');
        }

        $this->writeCurrentContext($user, $ip, $geo);
    }

    /**
     * @return array{ip:string,city:?string,country:?string,isp:?string}
     */
    private function lookupIp(string $ip): array
    {
        if ($this->isPrivateIp($ip)) {
            return $this->lookupPublicRequesterIp($ip);
        }

        try {
            $response = $this->httpClient->request(
                'GET',
                'http://ip-api.com/json/' . rawurlencode($ip),
                [
                    'query' => [
                        'fields' => 'status,message,country,city,query,isp',
                        'lang' => 'fr',
                    ],
                    'timeout' => 2.5,
                ]
            );
            $data = $response->toArray(false);
        } catch (\Throwable) {
            $data = [];
        }

        if (($data['status'] ?? null) !== 'success') {
            return [
                'ip' => $ip,
                'city' => null,
                'country' => null,
                'isp' => null,
            ];
        }

        return [
            'ip' => (string) ($data['query'] ?? $ip),
            'city' => $this->nullableString($data['city'] ?? null),
            'country' => $this->nullableString($data['country'] ?? null),
            'isp' => $this->nullableString($data['isp'] ?? null),
        ];
    }

    /**
     * Quand Symfony tourne en local, l'IP du client est souvent 127.0.0.1.
     * On demande alors a ip-api de geolocaliser l'IP publique sortante.
     *
     * @return array{ip:string,city:?string,country:?string,isp:?string}
     */
    private function lookupPublicRequesterIp(string $fallbackIp): array
    {
        try {
            $response = $this->httpClient->request(
                'GET',
                'http://ip-api.com/json/',
                [
                    'query' => [
                        'fields' => 'status,message,country,city,query,isp',
                        'lang' => 'fr',
                    ],
                    'timeout' => 2.5,
                ]
            );
            $data = $response->toArray(false);
        } catch (\Throwable) {
            $data = [];
        }

        if (($data['status'] ?? null) !== 'success') {
            return [
                'ip' => $fallbackIp,
                'city' => 'Local',
                'country' => 'Local',
                'isp' => 'Reseau local',
            ];
        }

        return [
            'ip' => (string) ($data['query'] ?? $fallbackIp),
            'city' => $this->nullableString($data['city'] ?? null),
            'country' => $this->nullableString($data['country'] ?? null),
            'isp' => $this->nullableString($data['isp'] ?? null),
        ];
    }

    /**
     * @param array<string,mixed>|null $previous
     * @param array{ip:string,city:?string,country:?string,isp:?string} $current
     */
    private function isUnusualLocation(?array $previous, array $current): bool
    {
        if ($previous === null) {
            return false;
        }

        $previousCountry = (string) ($previous['country'] ?? '');
        $previousCity = (string) ($previous['city'] ?? '');
        $currentCountry = (string) ($current['country'] ?? '');
        $currentCity = (string) ($current['city'] ?? '');

        if ($previousCountry !== '' && $currentCountry !== '' && $previousCountry !== $currentCountry) {
            return true;
        }

        return $previousCity !== '' && $currentCity !== '' && $previousCity !== $currentCity;
    }

    private function resolveClientIp(Request $request): string
    {
        $testIp = trim((string) $this->securityTestIp);
        if ($testIp !== '' && filter_var($testIp, FILTER_VALIDATE_IP)) {
            return $testIp;
        }

        return (string) ($request->getClientIp() ?: '127.0.0.1');
    }

    private function isPrivateIp(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function readPreviousContext(User $user): ?array
    {
        $path = $this->getContextPath($user);
        if (!is_file($path)) {
            return null;
        }

        $content = file_get_contents($path);
        if ($content === false) {
            return null;
        }

        $data = json_decode($content, true);

        return is_array($data) ? $data : null;
    }

    /**
     * @param array{ip:string,city:?string,country:?string,isp:?string} $geo
     */
    private function writeCurrentContext(User $user, string $ip, array $geo): void
    {
        $dir = $this->projectDir . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . self::LOGIN_CONTEXT_DIR;
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        file_put_contents($this->getContextPath($user), json_encode([
            'ip' => $ip,
            'city' => $geo['city'],
            'country' => $geo['country'],
            'isp' => $geo['isp'],
            'checkedAt' => (new \DateTimeImmutable())->format(DATE_ATOM),
        ], JSON_PRETTY_PRINT));
    }

    private function getContextPath(User $user): string
    {
        return $this->projectDir
            . DIRECTORY_SEPARATOR . 'var'
            . DIRECTORY_SEPARATOR . self::LOGIN_CONTEXT_DIR
            . DIRECTORY_SEPARATOR . 'user_' . $user->getId() . '.json';
    }

    private function nullableString(mixed $value): ?string
    {
        $string = trim((string) $value);

        return $string === '' ? null : $string;
    }
}
