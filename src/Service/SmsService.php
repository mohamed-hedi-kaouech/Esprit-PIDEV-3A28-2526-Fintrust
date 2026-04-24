<?php

namespace App\Service;

use Psr\Log\LoggerInterface;
use Twilio\Rest\Client;

/**
 * Service for sending SMS via Twilio.
 */
class SmsService
{
    public function __construct(
        private ?string $twilioSid,
        private ?string $twilioAuthToken,
        private ?string $twilioFrom,
        private LoggerInterface $logger,
    ) {}

    /**
     * Send an SMS to a phone number.
     */
    public function send(string $to, string $message): bool
    {
        try {
            $twilioSid = trim((string) $this->twilioSid);
            $twilioAuthToken = trim((string) $this->twilioAuthToken);
            $twilioFrom = trim((string) $this->twilioFrom);

            if ($twilioSid === '' || $twilioAuthToken === '' || $twilioFrom === '') {
                $this->logger->warning('SMS non envoye: configuration Twilio manquante.', [
                    'to' => $to,
                ]);

                return false;
            }

            $client = new Client($twilioSid, $twilioAuthToken);

            $client->messages->create($to, [
                'from' => $twilioFrom,
                'body' => $message,
            ]);

            $this->logger->info('SMS sent to ' . $to);

            return true;
        } catch (\Exception $e) {
            $this->logger->error('SMS failed to ' . $to . ': ' . $e->getMessage());

            return false;
        }
    }
}
