<?php

namespace App\Service;

use Endroid\QrCode\Builder\BuilderInterface;

/**
 * Service - Generation de QR codes clients avec EndroidQrCodeBundle.
 */
class QrCodeService
{
    public function __construct(
        private readonly BuilderInterface $defaultQrCodeBuilder,
        private readonly ?string $fintrustPublicUrl = '',
    ) {}

    /**
     * Genere un token unique securise pour le QR code d'un utilisateur.
     * 48 caracteres hexadecimaux (24 octets aleatoires).
     */
    public function generateToken(): string
    {
        return bin2hex(random_bytes(24));
    }

    /**
     * Retourne le SVG du QR code pour un token donne.
     * Le QR code encode l'URL publique du profil client.
     *
     * @param string $token   Token unique du client
     * @param string $baseUrl URL de base de l'application (ex: https://fintrust.tn)
     */
    public function getQrSvg(string $token, string $baseUrl = ''): string
    {
        $builder = clone $this->defaultQrCodeBuilder;

        return $builder
            ->data($this->getPublicProfileUrl($token, $baseUrl))
            ->build()
            ->getString();
    }

    public function getPublicProfileUrl(string $token, string $baseUrl = ''): string
    {
        $resolvedBaseUrl = $this->resolveBaseUrl($baseUrl);

        return $resolvedBaseUrl . '/espace-client/qr/' . $token;
    }

    public function isLocalOnlyUrl(string $baseUrl = ''): bool
    {
        $resolvedBaseUrl = $this->resolveBaseUrl($baseUrl);
        $host = (string) parse_url($resolvedBaseUrl, PHP_URL_HOST);
        $host = strtolower($host);

        return in_array($host, ['localhost', '127.0.0.1', '::1'], true);
    }

    private function resolveBaseUrl(string $baseUrl = ''): string
    {
        $configuredBaseUrl = trim((string) $this->fintrustPublicUrl);
        if ($configuredBaseUrl !== '') {
            return rtrim($configuredBaseUrl, '/');
        }

        return rtrim($baseUrl, '/');
    }
}
