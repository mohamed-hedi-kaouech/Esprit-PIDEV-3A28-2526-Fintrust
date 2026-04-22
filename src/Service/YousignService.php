<?php

namespace App\Service;

use App\Entity\Wallet\Cheque;
use Dompdf\Dompdf;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\Multipart\FormDataPart;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class YousignService
{
    private const DELIVERY_MODE_EMAIL = 'email';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        private readonly string $yousignApiKey,
        private readonly string $yousignBaseUrl,
        private readonly string $yousignWebhookSecret,
        private readonly string $appUrl,
    ) {
    }

    public function isConfigured(): bool
    {
        return trim($this->yousignApiKey) !== '' && trim($this->yousignBaseUrl) !== '';
    }

    /**
     * Initiates an e-signature request on Yousign for a cheque.
     *
     * @return array{procedure_id: string, signing_link: string}
     *
     * @throws \RuntimeException if the API key is not configured or if any API call fails
     */
    public function initiateSignature(
        Cheque $cheque,
        string $signerEmail,
        string $signerFirstName,
        string $signerLastName
    ): array {
        if (trim($this->yousignApiKey) === '') {
            throw new \RuntimeException('La cle API Yousign n est pas configuree (YOUSIGN_API_KEY manquant dans .env.local).');
        }
        if (trim($this->yousignBaseUrl) === '') {
            throw new \RuntimeException('L URL Yousign n est pas configuree (YOUSIGN_BASE_URL manquant).');
        }
        if (trim($this->appUrl) === '') {
            throw new \RuntimeException('APP_URL n est pas configure. Cette URL est necessaire pour les redirections et le webhook.');
        }
        if (!filter_var($signerEmail, FILTER_VALIDATE_EMAIL)) {
            throw new \RuntimeException('Email signataire invalide pour Yousign.');
        }
        if (trim($signerFirstName) === '' || trim($signerLastName) === '') {
            throw new \RuntimeException('Nom et prenom du signataire obligatoires pour Yousign.');
        }

        $signingRequest = $this->createSigningRequest($cheque);
        $requestId = (string) ($signingRequest['id'] ?? '');
        if ($requestId === '') {
            throw new \RuntimeException('Yousign n a pas retourne d identifiant de demande de signature.');
        }

        $documentId = $this->uploadDocument($requestId, $cheque);

        $signerId = $this->addSigner($requestId, $documentId, $signerEmail, $signerFirstName, $signerLastName, $cheque);

        $activatedRequest = $this->activateSigningRequest($requestId);
        $activatedSigner = $this->extractSigner($activatedRequest, $signerId);
        $signingLink = $this->extractSigningLinkFromSigner($activatedSigner);
        $signerStatus = (string) ($activatedSigner['status'] ?? '');
        $signerDetails = $this->fetchSigner($requestId, $signerId);
        $signingLink = $signingLink !== '' ? $signingLink : $this->extractSigningLinkFromSigner($signerDetails);
        $signerStatus = (string) ($signerDetails['status'] ?? $signerStatus);
        if ($signingLink === '') {
            $this->logger->warning('Yousign: lien de signature non retourne, email Yousign attendu', [
                'cheque_id' => $cheque->getIdCheque(),
                'procedure_id' => $requestId,
                'signer_email' => $signerEmail,
            ]);
        }

        $this->logger->info('Yousign: signature initiée', [
            'cheque_id' => $cheque->getIdCheque(),
            'procedure_id' => $requestId,
            'signer_email' => $signerEmail,
            'signature_request_delivery_mode' => self::DELIVERY_MODE_EMAIL,
            'signer_delivery_mode' => self::DELIVERY_MODE_EMAIL,
            'signer_status_after_activation' => $signerStatus !== '' ? $signerStatus : 'unknown',
            'base_url' => $this->normalizedBaseUrl(),
            'link_available' => $signingLink !== '',
        ]);

        return [
            'procedure_id' => $requestId,
            'signing_link' => $signingLink,
        ];
    }

    /**
     * Verifies the Yousign webhook HMAC-SHA256 signature.
     * Returns true when the secret is not configured (dev/staging without webhook secret).
     */
    public function verifyWebhookSignature(string $rawPayload, string $signatureHeader): bool
    {
        if (empty($this->yousignWebhookSecret)) {
            return true;
        }

        // Header format: "sha256=<hex>"
        $expected = 'sha256=' . hash_hmac('sha256', $rawPayload, $this->yousignWebhookSecret);

        return hash_equals($expected, $signatureHeader);
    }

    // -------------------------------------------------------------------------
    // Private API call helpers
    // -------------------------------------------------------------------------

    private function createSigningRequest(Cheque $cheque): array
    {
        $response = $this->httpClient->request('POST', $this->apiUrl('/signature_requests'), [
            'headers' => $this->jsonHeaders(),
            'json' => [
                'name' => 'FinTrust - Cheque ' . $cheque->getNumeroCheque(),
                'delivery_mode' => self::DELIVERY_MODE_EMAIL,
                'timezone' => 'Africa/Tunis',
                'external_id' => 'cheque-' . $cheque->getIdCheque(),
            ],
        ]);

        return $this->decodeOrFail($response, 'create signing request');
    }

    private function uploadDocument(string $requestId, Cheque $cheque): string
    {
        $pdfContent = $this->generateChequePdf($cheque);
        $filename = 'cheque_' . $cheque->getNumeroCheque() . '.pdf';

        $formData = new FormDataPart([
            'file' => new DataPart($pdfContent, $filename, 'application/pdf'),
            'nature' => 'signable_document',
        ]);

        $response = $this->httpClient->request(
            'POST',
            $this->apiUrl('/signature_requests/' . $requestId . '/documents'),
            [
                'headers' => array_merge(
                    ['Authorization' => 'Bearer ' . trim($this->yousignApiKey)],
                    $formData->getPreparedHeaders()->toArray()
                ),
                'body' => $formData->bodyToString(),
            ]
        );

        $data = $this->decodeOrFail($response, 'upload document');

        $documentId = (string) ($data['id'] ?? '');
        if ($documentId === '') {
            throw new \RuntimeException('Yousign n a pas retourne d identifiant document.');
        }

        return $documentId;
    }

    private function addSigner(
        string $requestId,
        string $documentId,
        string $email,
        string $firstName,
        string $lastName,
        Cheque $cheque
    ): string {
        $response = $this->httpClient->request(
            'POST',
            $this->apiUrl('/signature_requests/' . $requestId . '/signers'),
            [
                'headers' => $this->jsonHeaders(),
                'json' => [
                    'info' => [
                        'first_name' => $firstName,
                        'last_name' => $lastName,
                        'email' => $email,
                        'locale' => 'fr',
                    ],
                    'delivery_mode' => self::DELIVERY_MODE_EMAIL,
                    'signature_level' => 'electronic_signature',
                    'signature_authentication_mode' => 'otp_email',
                    'fields' => [
                        [
                            'document_id' => $documentId,
                            'type' => 'signature',
                            'page' => 1,
                            'x' => 77,
                            'y' => 580,
                            'width' => 250,
                            'height' => 55,
                        ],
                    ],
                    // Trial Yousign subscriptions reject redirect URLs.
                    // Re-enable redirect_urls only for a non-trial subscription if needed.
                ],
            ]
        );

        $data = $this->decodeOrFail($response, 'add signer');

        $signerId = (string) ($data['id'] ?? '');
        if ($signerId === '') {
            throw new \RuntimeException('Yousign n a pas retourne d identifiant signataire.');
        }

        return $signerId;
    }

    private function activateSigningRequest(string $requestId): array
    {
        $response = $this->httpClient->request(
            'POST',
            $this->apiUrl('/signature_requests/' . $requestId . '/activate'),
            ['headers' => $this->jsonHeaders()]
        );

        return $this->decodeOrFail($response, 'activate signing request');
    }

    private function fetchSigner(string $requestId, string $signerId): array
    {
        $response = $this->httpClient->request(
            'GET',
            $this->apiUrl('/signature_requests/' . $requestId . '/signers/' . $signerId),
            ['headers' => $this->jsonHeaders()]
        );

        return $this->decodeOrFail($response, 'fetch signer');
    }

    /**
     * @param array<string, mixed> $activatedRequest
     */
    private function extractSigner(array $activatedRequest, string $signerId): array
    {
        $signers = $activatedRequest['signers'] ?? [];
        if (!is_array($signers)) {
            return [];
        }

        foreach ($signers as $signer) {
            if (!is_array($signer)) {
                continue;
            }

            if ((string) ($signer['id'] ?? '') === $signerId) {
                return $signer;
            }
        }

        return [];
    }

    /**
     * @param array<string, mixed> $signer
     */
    private function extractSigningLinkFromSigner(array $signer): string
    {
        return (string) ($signer['signature_link'] ?? $signer['signing_link'] ?? '');
    }

    // -------------------------------------------------------------------------
    // PDF generation
    // -------------------------------------------------------------------------

    private function generateChequePdf(Cheque $cheque): string
    {
        $html = sprintf(
            '<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: Arial, sans-serif; padding: 40px; color: #222; }
        h1 { color: #1a5276; border-bottom: 2px solid #1a5276; padding-bottom: 8px; }
        table { width: 100%%; border-collapse: collapse; margin: 20px 0; }
        td { padding: 8px 12px; border: 1px solid #ddd; }
        td:first-child { font-weight: bold; width: 40%%; background: #f7f9fc; }
        .footer { margin-top: 60px; }
        .signature-area { border-top: 1px solid #555; width: 260px; margin-top: 80px; padding-top: 8px; font-size: 12px; color: #555; }
    </style>
</head>
<body>
    <h1>FinTrust &mdash; Demande de Signature &Eacute;lectronique</h1>
    <p>Document relatif &agrave; la demande de ch&egrave;que suivante :</p>
    <table>
        <tr><td>Num&eacute;ro ch&egrave;que</td><td>%s</td></tr>
        <tr><td>B&eacute;n&eacute;ficiaire</td><td>%s</td></tr>
        <tr><td>Montant</td><td>%s TND</td></tr>
        <tr><td>Date d&apos;&eacute;mission</td><td>%s</td></tr>
        <tr><td>Statut</td><td>%s</td></tr>
    </table>
    <p>
        Je soussign&eacute;(e) reconnais avoir pris connaissance de cette demande de ch&egrave;que
        et certifie l&apos;exactitude des informations ci-dessus.
        J&apos;autorise FinTrust &agrave; traiter cette demande conform&eacute;ment aux conditions
        g&eacute;n&eacute;rales du service.
    </p>
    <div class="footer">
        <div class="signature-area">Signature du client</div>
    </div>
</body>
</html>',
            htmlspecialchars($cheque->getNumeroCheque()),
            htmlspecialchars($cheque->getBeneficiaire() ?? 'Non spécifié'),
            number_format($cheque->getMontant(), 2, ',', ' '),
            $cheque->getDateEmission()->format('d/m/Y'),
            htmlspecialchars($cheque->getStatut())
        );

        $dompdf = new Dompdf(['isHtml5ParserEnabled' => true]);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return (string) $dompdf->output();
    }

    // -------------------------------------------------------------------------
    // HTTP helpers
    // -------------------------------------------------------------------------

    /** @return array<string, string> */
    private function jsonHeaders(): array
    {
        return [
            'Authorization' => 'Bearer ' . trim($this->yousignApiKey),
            'Content-Type' => 'application/json',
        ];
    }

    private function decodeOrFail(\Symfony\Contracts\HttpClient\ResponseInterface $response, string $step): array
    {
        $statusCode = $response->getStatusCode();

        if ($statusCode < 200 || $statusCode >= 300) {
            $body = $response->getContent(false);
            $this->logger->error('Yousign API error at step: ' . $step, [
                'status' => $statusCode,
                'body' => $body,
                'base_url' => $this->normalizedBaseUrl(),
            ]);
            throw new \RuntimeException(sprintf(
                'Yousign API (%s) a retourné HTTP %d : %s',
                $step,
                $statusCode,
                $body
            ));
        }

        return $response->toArray();
    }

    private function normalizedBaseUrl(): string
    {
        return rtrim(trim($this->yousignBaseUrl), '/');
    }

    private function apiUrl(string $path): string
    {
        return $this->normalizedBaseUrl() . '/' . ltrim($path, '/');
    }
}
