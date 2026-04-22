<?php

namespace App\Service;

use App\Entity\User\User;
use Symfony\Component\HttpKernel\KernelInterface;

class WalletAuditService
{
    private string $logPath;

    public function __construct(KernelInterface $kernel)
    {
        $this->logPath = $kernel->getProjectDir() . '/var/log/wallet_audit.log';
    }

    /**
     * @param array<string, mixed> $context
     */
    public function log(string $action, array $context = []): void
    {
        $payload = array_merge([
            'timestamp' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'action' => $action,
        ], $context);

        $directory = \dirname($this->logPath);
        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        file_put_contents($this->logPath, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL, FILE_APPEND);
    }

    public function logTransfer(array $transfer, User $sender, ?User $recipient = null): void
    {
        $this->log('wallet.transfer.validated', [
            'transfer_id' => $transfer['id'] ?? null,
            'reference' => $transfer['reference'] ?? null,
            'sender_user_id' => $sender->getId(),
            'recipient_user_id' => $recipient?->getId(),
            'source_wallet_id' => $transfer['source_wallet_id'] ?? null,
            'destination_wallet_id' => $transfer['destination_wallet_id'] ?? null,
            'amount' => $transfer['amount'] ?? null,
            'status' => $transfer['status'] ?? null,
            'label' => $transfer['label'] ?? null,
        ]);
    }

    public function logChequeAction(string $action, int $chequeId, int $walletId, ?int $userId = null, ?string $status = null, ?string $reason = null): void
    {
        $this->log($action, [
            'cheque_id' => $chequeId,
            'wallet_id' => $walletId,
            'user_id' => $userId,
            'status' => $status,
            'reason' => $reason,
        ]);
    }

    public function logWalletStatusChange(int $walletId, ?int $userId, string $status, bool $active, bool $blocked): void
    {
        $this->log('wallet.status.changed', [
            'wallet_id' => $walletId,
            'user_id' => $userId,
            'status' => $status,
            'active' => $active,
            'blocked' => $blocked,
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getRecentEntries(int $limit = 50, ?int $userId = null): array
    {
        if (!is_file($this->logPath)) {
            return [];
        }

        $lines = file($this->logPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return [];
        }

        $entries = [];

        foreach (array_reverse($lines) as $line) {
            $decoded = json_decode($line, true);
            if (!is_array($decoded)) {
                continue;
            }

            if ($userId !== null) {
                $matchesUser = ($decoded['user_id'] ?? null) === $userId
                    || ($decoded['sender_user_id'] ?? null) === $userId
                    || ($decoded['recipient_user_id'] ?? null) === $userId;

                if (!$matchesUser) {
                    continue;
                }
            }

            $entries[] = $decoded;

            if (count($entries) >= $limit) {
                break;
            }
        }

        return $entries;
    }
}
