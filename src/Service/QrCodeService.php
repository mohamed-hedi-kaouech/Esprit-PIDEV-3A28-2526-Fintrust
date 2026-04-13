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
        $data = urlencode($baseUrl . '/espace-client/qr/' . $token);
        return "https://api.qrserver.com/v1/create-qr-code/?size=200x200&data={$data}";
    }
}
