<?php

namespace App\Service\Notification;

use App\Entity\User\Client\Notification;

class NotificationFormatter
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Notification $notification): array
    {
        $message = $notification->getMessage();

        return [
            'id' => $notification->getId(),
            'title' => $this->extractTitle($message, $notification->getType()),
            'message' => $message,
            'type' => $notification->getType(),
            'severity' => $this->mapSeverity($notification->getType()),
            'category' => $notification->getUser()->isAdmin() ? 'admin' : 'client',
            'recipient' => [
                'id' => $notification->getUser()->getId(),
                'name' => $notification->getUser()->getFullName(),
                'role' => $notification->getUser()->getRole(),
            ],
            'wallet_id' => $this->extractWalletId($message),
            'read' => $notification->isRead(),
            'created_at' => $notification->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'link' => $this->resolveLink($message),
        ];
    }

    private function extractTitle(string $message, string $type): string
    {
        $parts = explode('|', $message, 2);
        $title = trim($parts[0] ?? '');

        if ($title !== '') {
            return $title;
        }

        return match (mb_strtoupper($type)) {
            'SUCCESS' => 'Confirmation bancaire',
            'WARNING' => 'Alerte de vigilance',
            'ERROR' => 'Incident bancaire',
            default => 'Notification FinTrust',
        };
    }

    private function mapSeverity(string $type): string
    {
        return match (mb_strtoupper($type)) {
            'ERROR' => 'critical',
            'WARNING' => 'warning',
            'SUCCESS' => 'success',
            default => 'info',
        };
    }

    private function extractWalletId(string $message): ?int
    {
        if (preg_match('/wallet\s*#?(\d+)/i', $message, $matches)) {
            return (int) $matches[1];
        }

        return null;
    }

    private function resolveLink(string $message): ?string
    {
        $normalized = mb_strtolower($message);

        if (str_contains($normalized, 'cheque') || str_contains($normalized, 'chequier')) {
            return '/espace-client/wallet/cheques';
        }

        if (str_contains($normalized, 'transfert')) {
            return '/espace-client/wallet/transferts';
        }

        if (str_contains($normalized, 'wallet')) {
            return '/espace-client/wallet';
        }

        return null;
    }
}
