<?php

namespace App\Service;

use App\Dto\WalletTransferData;
use App\Entity\User\User;
use App\Entity\Wallet\Wallet;
use App\Exception\WalletTransferException;

class MerchantQrPaymentService
{
    public function __construct(
        private readonly WalletTransferService $walletTransferService,
        private readonly WalletAuditService $walletAuditService,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function generatePayload(array $payload): array
    {
        $qr = $this->normalizeQrPayload($payload);
        $walletId = $this->nullableInt($qr['merchant_wallet_id'] ?? null);
        $merchantWallet = $this->resolveMerchantWallet($qr);
        $amount = $this->resolveAmount([], $qr);
        $qrType = $this->resolveQrType($qr, $amount);

        $qrPayload = [
            'type' => $qrType,
            'merchant_wallet_id' => $walletId,
            'merchant_name' => $this->resolveMerchantName($qr, $merchantWallet),
            'reference' => $this->resolveReference($qr),
        ];

        if ($amount !== null && $amount > 0.0) {
            $qrPayload['amount'] = $amount;
        }

        if (trim((string) ($qr['transaction_id'] ?? '')) !== '') {
            $qrPayload['transaction_id'] = trim((string) $qr['transaction_id']);
        } elseif ($qrType === 'dynamic') {
            $qrPayload['transaction_id'] = 'MQR-TX-' . (new \DateTimeImmutable())->format('YmdHis');
        }

        return [
            'valid' => $walletId !== null,
            'errors' => $walletId === null ? ['Le wallet commercant est obligatoire.'] : [],
            'qr_type' => $qrType,
            'qr_payload' => $qrPayload,
            'qr_data' => json_encode($qrPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'merchant_found' => $merchantWallet instanceof Wallet,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function preview(User $user, array $payload): array
    {
        $qr = $this->resolveQrPayload($payload);
        $sourceWallet = $this->walletTransferService->findWalletForUser($user);
        $merchantWallet = $this->resolveMerchantWallet($qr);
        $amount = $this->resolveAmount($payload, $qr);
        $reference = $this->resolveReference($qr);
        $qrType = $this->resolveQrType($qr, $amount);

        $errors = [];
        if (!$sourceWallet instanceof Wallet) {
            $errors[] = 'Le wallet client est introuvable.';
        } elseif (!$sourceWallet->getEstActif() || $sourceWallet->getEstBloque()) {
            $errors[] = 'Le wallet client est inactif ou bloque.';
        }

        if (!$merchantWallet instanceof Wallet) {
            $errors[] = 'Le wallet commercant est introuvable.';
        } elseif (!$merchantWallet->getEstActif() || $merchantWallet->getEstBloque()) {
            $errors[] = 'Le wallet commercant est inactif ou bloque.';
        }

        if ($sourceWallet instanceof Wallet && $merchantWallet instanceof Wallet && $sourceWallet->getIdWallet() === $merchantWallet->getIdWallet()) {
            $errors[] = 'Le wallet source et le wallet commercant doivent etre differents.';
        }

        if ($amount === null || $amount <= 0.0) {
            $errors[] = 'Le montant est obligatoire pour confirmer le paiement.';
        } elseif ($sourceWallet instanceof Wallet && round((float) $sourceWallet->getSolde(), 2) < $amount) {
            $errors[] = 'Solde insuffisant pour effectuer ce paiement.';
        }

        return [
            'valid' => $errors === [],
            'errors' => $errors,
            'merchant' => [
                'wallet_id' => $merchantWallet?->getIdWallet() ?? $this->nullableInt($qr['merchant_wallet_id'] ?? null),
                'name' => $this->resolveMerchantName($qr, $merchantWallet),
            ],
            'payment' => [
                'qr_type' => $qrType,
                'amount' => $amount,
                'currency' => $sourceWallet?->getDevise() ?? $merchantWallet?->getDevise() ?? 'TND',
                'reference' => $reference,
                'transaction_id' => trim((string) ($qr['transaction_id'] ?? '')) !== '' ? trim((string) $qr['transaction_id']) : null,
                'free_amount' => $qrType === 'static',
            ],
            'source_wallet' => $sourceWallet instanceof Wallet ? [
                'wallet_id' => $sourceWallet->getIdWallet(),
                'owner' => $sourceWallet->getNomProprietaire(),
                'balance' => round((float) $sourceWallet->getSolde(), 2),
                'currency' => $sourceWallet->getDevise(),
                'status' => $sourceWallet->getStatut(),
            ] : null,
            'qr_payload' => $qr,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     *
     * @throws WalletTransferException
     */
    public function pay(User $user, array $payload): array
    {
        $preview = $this->preview($user, $payload);
        if (($preview['valid'] ?? false) !== true) {
            throw new WalletTransferException(implode(' ', $preview['errors'] ?? ['Paiement QR invalide.']));
        }

        $merchant = is_array($preview['merchant'] ?? null) ? $preview['merchant'] : [];
        $payment = is_array($preview['payment'] ?? null) ? $preview['payment'] : [];

        $data = (new WalletTransferData())
            ->setRecipient((string) ($merchant['wallet_id'] ?? ''))
            ->setAmount((float) ($payment['amount'] ?? 0.0))
            ->setLabel($this->buildTransferLabel($merchant, $payment));

        $transfer = $this->walletTransferService->transfer($user, $data);

        $this->walletAuditService->log('wallet.merchant_qr_payment.validated', [
            'user_id' => $user->getId(),
            'transfer_id' => $transfer['id'] ?? null,
            'transfer_reference' => $transfer['reference'] ?? null,
            'merchant_wallet_id' => $merchant['wallet_id'] ?? null,
            'merchant_name' => $merchant['name'] ?? null,
            'merchant_reference' => $payment['reference'] ?? null,
            'merchant_transaction_id' => $payment['transaction_id'] ?? null,
            'qr_type' => $payment['qr_type'] ?? null,
            'amount' => $payment['amount'] ?? null,
            'status' => 'VALIDE',
        ]);

        return [
            'success' => true,
            'preview' => $preview,
            'transfer' => $transfer,
            'message' => sprintf(
                'Paiement confirme pour %s: %.2f %s.',
                (string) ($merchant['name'] ?? 'commercant'),
                (float) ($payment['amount'] ?? 0.0),
                (string) ($payment['currency'] ?? 'TND')
            ),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function resolveQrPayload(array $payload): array
    {
        $raw = trim((string) ($payload['qr_data'] ?? $payload['qr'] ?? ''));
        if ($raw !== '') {
            $json = json_decode($raw, true);
            if (is_array($json)) {
                return $this->normalizeQrPayload($json);
            }

            parse_str($raw, $queryPayload);
            if (is_array($queryPayload) && $queryPayload !== []) {
                return $this->normalizeQrPayload($queryPayload);
            }
        }

        return $this->normalizeQrPayload($payload);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function normalizeQrPayload(array $payload): array
    {
        return [
            'merchant_wallet_id' => $payload['merchant_wallet_id'] ?? $payload['wallet_id'] ?? $payload['merchantWalletId'] ?? null,
            'merchant_name' => trim((string) ($payload['merchant_name'] ?? $payload['merchant'] ?? $payload['merchantName'] ?? '')),
            'amount' => $payload['amount'] ?? null,
            'reference' => trim((string) ($payload['reference'] ?? $payload['ref'] ?? '')),
            'transaction_id' => trim((string) ($payload['transaction_id'] ?? $payload['transactionId'] ?? $payload['merchant_transaction_id'] ?? '')),
            'type' => trim((string) ($payload['type'] ?? $payload['qr_type'] ?? '')),
        ];
    }

    /**
     * @param array<string, mixed> $qr
     */
    private function resolveMerchantWallet(array $qr): ?Wallet
    {
        $walletId = $this->nullableInt($qr['merchant_wallet_id'] ?? null);
        if ($walletId === null) {
            return null;
        }

        return $this->walletTransferService->findDestinationWallet((string) $walletId);
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $qr
     */
    private function resolveAmount(array $payload, array $qr): ?float
    {
        $amount = $qr['amount'] ?? null;
        if ($amount === null || trim((string) $amount) === '' || (float) str_replace(',', '.', (string) $amount) <= 0.0) {
            $amount = $payload['amount'] ?? null;
        }

        if ($amount === null || trim((string) $amount) === '') {
            return null;
        }

        return round((float) str_replace(',', '.', (string) $amount), 2);
    }

    private function nullableInt(mixed $value): ?int
    {
        if ($value === null || trim((string) $value) === '' || !ctype_digit((string) $value)) {
            return null;
        }

        return (int) $value;
    }

    /**
     * @param array<string, mixed> $qr
     */
    private function resolveReference(array $qr): string
    {
        $reference = trim((string) ($qr['reference'] ?? ''));

        return $reference !== '' ? $reference : 'MQR-' . (new \DateTimeImmutable())->format('YmdHis');
    }

    /**
     * @param array<string, mixed> $qr
     */
    private function resolveQrType(array $qr, ?float $amount): string
    {
        $type = mb_strtolower(trim((string) ($qr['type'] ?? '')));
        if (in_array($type, ['static', 'dynamic'], true)) {
            return $type;
        }

        return $amount !== null && $amount > 0.0 ? 'dynamic' : 'static';
    }

    /**
     * @param array<string, mixed> $qr
     */
    private function resolveMerchantName(array $qr, ?Wallet $merchantWallet): string
    {
        $name = trim((string) ($qr['merchant_name'] ?? ''));
        if ($name !== '') {
            return $name;
        }

        if ($merchantWallet instanceof Wallet && trim((string) $merchantWallet->getNomProprietaire()) !== '') {
            return trim((string) $merchantWallet->getNomProprietaire());
        }

        return $merchantWallet instanceof Wallet ? 'Commercant #' . $merchantWallet->getIdWallet() : 'Commercant inconnu';
    }

    /**
     * @param array<string, mixed> $merchant
     * @param array<string, mixed> $payment
     */
    private function buildTransferLabel(array $merchant, array $payment): string
    {
        return sprintf(
            'Paiement QR commercant | %s | Ref commercant: %s%s',
            (string) ($merchant['name'] ?? 'Commercant'),
            (string) ($payment['reference'] ?? 'N/A'),
            isset($payment['transaction_id']) && $payment['transaction_id'] !== null ? ' | Tx commercant: ' . (string) $payment['transaction_id'] : ''
        );
    }
}
