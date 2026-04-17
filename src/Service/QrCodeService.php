<?php

namespace App\Service;

/**
 * Service — Génération de QR codes clients.
 *
 * Utilise l'API publique api.qrserver.com (aucune dépendance Composer requise).
 * Extensible pour utiliser endroid/qr-code ou BaconQrCode si nécessaire.
 */
class QrCodeService
{
    public function __construct(
        private readonly string $fintrustPublicUrl = '',
    ) {}

    /**
     * Génère un token unique sécurisé pour le QR code d'un utilisateur.
     * 48 caractères hexadécimaux (24 octets aléatoires).
     */
    public function generateToken(): string
    {
        return bin2hex(random_bytes(24));
    }

    /**
     * Retourne l'URL de l'image QR code pour un token donné.
     * Le QR code encode l'URL publique du profil client.
     *
     * @param string $token   Token unique du client
     * @param string $baseUrl URL de base de l'application (ex: https://fintrust.tn)
     */
    public function getQrImageUrl(string $token, string $baseUrl = ''): string
    {
        $data = urlencode($this->getPublicProfileUrl($token, $baseUrl));

        return "https://api.qrserver.com/v1/create-qr-code/?size=320x320&margin=16&ecc=M&data={$data}";
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
