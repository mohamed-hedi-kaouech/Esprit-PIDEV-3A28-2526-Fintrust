<?php

namespace App\Service;

use App\Entity\User\User;
use App\Exception\InternationalTransferException;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class OtpVerificationService
{
    private const TWILIO_VERIFY_BASE_URL = 'https://verify.twilio.com/v2/Services';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        private readonly string $twilioAccountSid,
        private readonly string $twilioAuthToken,
        private readonly string $twilioVerifyServiceSid,
        private readonly string $twilioVerifyChannel,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function sendCode(User $user, ?string $destinationPhone = null): array
    {
        $phoneNumber = $this->resolvePhoneNumber($user, $destinationPhone);
        $this->assertConfiguration();

        try {
            $response = $this->httpClient->request('POST', $this->buildTwilioUrl('/Verifications'), [
                'auth_basic' => [$this->twilioAccountSid, $this->twilioAuthToken],
                'body' => [
                    'To' => $phoneNumber,
                    'Channel' => $this->twilioVerifyChannel,
                ],
                'timeout' => 20,
            ]);

            $statusCode = $response->getStatusCode();
            $payload = $response->toArray(false);

            if ($statusCode >= 400) {
                $diagnosis = $this->diagnoseTwilioError($statusCode, $payload);

                $this->logger->error('Echec Twilio Verify lors de l envoi OTP.', [
                    'user_id' => $user->getId(),
                    'url' => $this->buildTwilioUrl('/Verifications'),
                    'phone_number' => $phoneNumber,
                    'channel' => $this->twilioVerifyChannel,
                    'status_code' => $statusCode,
                    'payload' => $payload,
                    'diagnosis' => $diagnosis,
                ]);

                throw new InternationalTransferException($diagnosis['user_message']);
            }

            return [
                'mode' => 'live',
                'phone_number' => $phoneNumber,
                'channel' => $this->twilioVerifyChannel,
                'user_message' => sprintf('Un code OTP a ete envoye par SMS au numero %s.', $this->maskPhone($phoneNumber)),
                'technical_reason' => null,
            ];
        } catch (TransportExceptionInterface $exception) {
            $this->logger->error('Erreur reseau Twilio Verify lors de l envoi OTP.', [
                'user_id' => $user->getId(),
                'url' => $this->buildTwilioUrl('/Verifications'),
                'phone_number' => $phoneNumber,
                'channel' => $this->twilioVerifyChannel,
                'message' => $exception->getMessage(),
                'exception' => $exception,
            ]);

            throw new InternationalTransferException('Le service OTP est temporairement indisponible. Veuillez reessayer dans quelques instants.', previous: $exception);
        }
    }

    public function verifyCode(User $user, string $code, ?string $destinationPhone = null): bool
    {
        $phoneNumber = $this->resolvePhoneNumber($user, $destinationPhone);
        $trimmedCode = trim($code);
        $this->assertConfiguration();

        try {
            $response = $this->httpClient->request('POST', $this->buildTwilioUrl('/VerificationCheck'), [
                'auth_basic' => [$this->twilioAccountSid, $this->twilioAuthToken],
                'body' => [
                    'To' => $phoneNumber,
                    'Code' => $trimmedCode,
                ],
                'timeout' => 20,
            ]);

            $statusCode = $response->getStatusCode();
            $payload = $response->toArray(false);

            if ($statusCode >= 400) {
                $diagnosis = $this->diagnoseTwilioError($statusCode, $payload);

                $this->logger->warning('Echec Twilio Verify lors de la verification OTP.', [
                    'user_id' => $user->getId(),
                    'url' => $this->buildTwilioUrl('/VerificationCheck'),
                    'phone_number' => $phoneNumber,
                    'status_code' => $statusCode,
                    'payload' => $payload,
                    'diagnosis' => $diagnosis,
                ]);

                return false;
            }

            return ($payload['status'] ?? null) === 'approved';
        } catch (TransportExceptionInterface $exception) {
            $this->logger->error('Erreur reseau Twilio Verify lors de la verification OTP.', [
                'user_id' => $user->getId(),
                'message' => $exception->getMessage(),
                'exception' => $exception,
            ]);

            throw new InternationalTransferException('Le service OTP est temporairement indisponible. Veuillez reessayer dans quelques instants.', previous: $exception);
        }
    }

    public function getMaskedPhone(User $user, ?string $destinationPhone = null): string
    {
        return $this->maskPhone($this->resolvePhoneNumber($user, $destinationPhone));
    }

    private function assertConfiguration(): void
    {
        if (
            $this->isMissingOrPlaceholder($this->twilioAccountSid)
            || $this->isMissingOrPlaceholder($this->twilioAuthToken)
            || $this->isMissingOrPlaceholder($this->twilioVerifyServiceSid)
        ) {
            throw new InternationalTransferException('La verification OTP bancaire n est pas configuree. Veuillez renseigner correctement les identifiants Twilio Verify.');
        }

        if (mb_strtolower(trim($this->twilioVerifyChannel)) !== 'sms') {
            throw new InternationalTransferException('Le canal Twilio Verify doit etre configure sur "sms" pour cette operation.');
        }
    }

    private function isMissingOrPlaceholder(string $value): bool
    {
        $normalized = trim($value);

        return $normalized === ''
            || str_contains($normalized, 'REMPLACE_PAR')
            || str_contains($normalized, 'VOTRE_');
    }

    private function resolvePhoneNumber(User $user, ?string $destinationPhone = null): string
    {
        $rawPhone = trim($destinationPhone ?? '');
        if ($rawPhone === '') {
            $rawPhone = trim((string) $user->getNumTel());
        }

        if ($rawPhone === '') {
            throw new InternationalTransferException('Aucun numero de telephone n est disponible pour recevoir le code OTP du transfert.');
        }

        $normalized = preg_replace('/[\s\-()]/', '', $rawPhone);
        if (!is_string($normalized) || $normalized === '') {
            throw new InternationalTransferException('Le numero de telephone utilise pour la verification OTP est invalide.');
        }

        if (str_starts_with($normalized, '00')) {
            $normalized = '+' . substr($normalized, 2);
        }

        if (!preg_match('/^\+[1-9][0-9]{7,14}$/', $normalized)) {
            throw new InternationalTransferException('Le numero de telephone doit etre au format international E.164, par exemple +216XXXXXXXX.');
        }

        return $normalized;
    }

    private function buildTwilioUrl(string $suffix): string
    {
        return sprintf('%s/%s%s', self::TWILIO_VERIFY_BASE_URL, $this->twilioVerifyServiceSid, $suffix);
    }

    private function maskPhone(string $phone): string
    {
        $length = strlen($phone);

        if ($length <= 4) {
            return $phone;
        }

        return substr($phone, 0, 3) . str_repeat('*', max(0, $length - 5)) . substr($phone, -2);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, string>
     */
    private function diagnoseTwilioError(int $statusCode, array $payload): array
    {
        $message = (string) ($payload['message'] ?? 'Erreur Twilio inconnue.');
        $code = (string) ($payload['code'] ?? '');
        $normalized = mb_strtolower($message);

        $technicalReason = match (true) {
            $statusCode === 401 || $code === '20003' => 'Authentification Twilio invalide: TWILIO_ACCOUNT_SID ou TWILIO_AUTH_TOKEN incorrect.',
            $statusCode === 404 => 'Verify Service SID introuvable: TWILIO_VERIFY_SERVICE_SID incorrect.',
            str_contains($normalized, 'channel') => 'Canal Twilio Verify non supporte ou mal configure.',
            str_contains($normalized, 'phone') || str_contains($normalized, 'to') => 'Numero destinataire invalide ou non supporte par Twilio Verify.',
            str_contains($normalized, 'service sid') => 'Verify Service SID invalide.',
            default => sprintf('Erreur Twilio Verify (%d): %s', $statusCode, $message),
        };

        $userMessage = match (true) {
            $statusCode === 401 || $code === '20003' => 'La verification OTP bancaire est indisponible car la configuration Twilio est invalide.',
            $statusCode === 404 => 'Le service OTP bancaire est mal configure. Veuillez contacter l administration technique.',
            str_contains($normalized, 'phone') || str_contains($normalized, 'to') => 'Le numero de telephone de votre profil n est pas compatible avec la verification OTP.',
            str_contains($normalized, 'channel') => 'Le canal OTP configure n est pas supporte pour le moment.',
            default => 'Le service OTP est temporairement indisponible. Veuillez reessayer dans quelques instants.',
        };

        return [
            'technical_reason' => $technicalReason,
            'user_message' => $userMessage,
        ];
    }
}
