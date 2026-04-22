<?php

namespace App\Service;

use App\Entity\User\User;
use App\Entity\Wallet\Cheque;
use Symfony\Component\HttpKernel\KernelInterface;

class ChequeSignatureService
{
    private string $secret;
    private string $auditPath;

    public function __construct(
        private readonly WalletAuditService $walletAuditService,
        KernelInterface $kernel,
    ) {
        $this->secret = (string) ($_ENV['APP_SECRET'] ?? $_SERVER['APP_SECRET'] ?? 'fintrust-local-signature');
        $this->auditPath = $kernel->getProjectDir() . '/var/log/wallet_audit.log';
    }

    public function sign(Cheque $cheque, User $admin): string
    {
        $signature = $this->buildSignature($cheque);
        $this->walletAuditService->log('wallet.cheque.signed', [
            'cheque_id' => $cheque->getIdCheque(),
            'wallet_id' => $cheque->getWallet()->getIdWallet(),
            'admin_id' => $admin->getId(),
            'status' => $cheque->getStatut(),
            'signature' => $signature,
            'fingerprint' => substr($signature, 0, 16),
        ]);

        return $signature;
    }

    /**
     * @return array{signed:bool, valid:bool, signature:?string, fingerprint:?string, signed_at:?string, admin_id:?int}
     */
    public function verify(Cheque $cheque): array
    {
        $entry = $this->findLastSignatureEntry($cheque);
        if ($entry === null) {
            return [
                'signed' => false,
                'valid' => false,
                'signature' => null,
                'fingerprint' => null,
                'signed_at' => null,
                'admin_id' => null,
            ];
        }

        $expected = $this->buildSignature($cheque);
        $signature = (string) ($entry['signature'] ?? '');

        return [
            'signed' => true,
            'valid' => hash_equals($expected, $signature),
            'signature' => $signature,
            'fingerprint' => (string) ($entry['fingerprint'] ?? substr($signature, 0, 16)),
            'signed_at' => (string) ($entry['timestamp'] ?? ''),
            'admin_id' => isset($entry['admin_id']) ? (int) $entry['admin_id'] : null,
        ];
    }

    private function buildSignature(Cheque $cheque): string
    {
        $payload = implode('|', [
            $cheque->getIdCheque(),
            $cheque->getNumeroCheque(),
            number_format($cheque->getMontant(), 2, '.', ''),
            $cheque->getWallet()->getIdWallet(),
            $cheque->getStatut(),
            $cheque->getDateEmission()->format(\DateTimeInterface::ATOM),
        ]);

        return hash_hmac('sha256', $payload, $this->secret);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findLastSignatureEntry(Cheque $cheque): ?array
    {
        if (!is_file($this->auditPath)) {
            return null;
        }

        $lines = file($this->auditPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return null;
        }

        foreach (array_reverse($lines) as $line) {
            $entry = json_decode($line, true);
            if (!is_array($entry)) {
                continue;
            }

            if (($entry['action'] ?? null) === 'wallet.cheque.signed' && (int) ($entry['cheque_id'] ?? 0) === $cheque->getIdCheque()) {
                return $entry;
            }
        }

        return null;
    }
}
