<?php

namespace App\Tests\Service;

use App\Entity\User\User;
use App\Entity\Wallet\Wallet;
use App\Service\MerchantQrPaymentService;
use App\Service\WalletAuditService;
use App\Service\WalletTransferService;
use PHPUnit\Framework\TestCase;

class MerchantQrPaymentServiceTest extends TestCase
{
    public function testGeneratePayloadReturnsErrorWhenMerchantWalletIsMissing(): void
    {
        $walletTransferService = $this->createMock(WalletTransferService::class);
        $walletAuditService = $this->createMock(WalletAuditService::class);
        $service = new MerchantQrPaymentService($walletTransferService, $walletAuditService);

        $result = $service->generatePayload([
            'merchant_name' => 'FinTrust Store',
            'amount' => 25.50,
        ]);

        self::assertFalse($result['valid']);
        self::assertSame(['Le wallet commercant est obligatoire.'], $result['errors']);
    }

    public function testPreviewReturnsErrorWhenSourceWalletIsMissing(): void
    {
        $user = (new User())->setRole(User::ROLE_CLIENT);
        $merchantWallet = $this->createWallet(20, 2, '150.00', true, false, 'Merchant Wallet');

        $walletTransferService = $this->createMock(WalletTransferService::class);
        $walletTransferService
            ->method('findWalletForUser')
            ->with($user)
            ->willReturn(null);
        $walletTransferService
            ->method('findDestinationWallet')
            ->with('20')
            ->willReturn($merchantWallet);

        $walletAuditService = $this->createMock(WalletAuditService::class);
        $service = new MerchantQrPaymentService($walletTransferService, $walletAuditService);

        $result = $service->preview($user, [
            'merchant_wallet_id' => '20',
            'amount' => 40,
        ]);

        self::assertFalse($result['valid']);
        self::assertContains('Le wallet client est introuvable.', $result['errors']);
    }

    public function testPreviewReturnsErrorWhenSourceWalletIsBlocked(): void
    {
        $user = (new User())->setRole(User::ROLE_CLIENT);
        $sourceWallet = $this->createWallet(10, 1, '200.00', true, true, 'Client Wallet');
        $merchantWallet = $this->createWallet(20, 2, '150.00', true, false, 'Merchant Wallet');

        $walletTransferService = $this->createMock(WalletTransferService::class);
        $walletTransferService->method('findWalletForUser')->willReturn($sourceWallet);
        $walletTransferService->method('findDestinationWallet')->willReturn($merchantWallet);

        $walletAuditService = $this->createMock(WalletAuditService::class);
        $service = new MerchantQrPaymentService($walletTransferService, $walletAuditService);

        $result = $service->preview($user, [
            'merchant_wallet_id' => '20',
            'amount' => 40,
        ]);

        self::assertFalse($result['valid']);
        self::assertContains('Le wallet client est inactif ou bloque.', $result['errors']);
    }

    public function testPreviewReturnsErrorWhenBalanceIsInsufficient(): void
    {
        $user = (new User())->setRole(User::ROLE_CLIENT);
        $sourceWallet = $this->createWallet(10, 1, '20.00', true, false, 'Client Wallet');
        $merchantWallet = $this->createWallet(20, 2, '150.00', true, false, 'Merchant Wallet');

        $walletTransferService = $this->createMock(WalletTransferService::class);
        $walletTransferService->method('findWalletForUser')->willReturn($sourceWallet);
        $walletTransferService->method('findDestinationWallet')->willReturn($merchantWallet);

        $walletAuditService = $this->createMock(WalletAuditService::class);
        $service = new MerchantQrPaymentService($walletTransferService, $walletAuditService);

        $result = $service->preview($user, [
            'merchant_wallet_id' => '20',
            'amount' => 40,
        ]);

        self::assertFalse($result['valid']);
        self::assertContains('Solde insuffisant pour effectuer ce paiement.', $result['errors']);
    }

    public function testPreviewReturnsValidDataForActiveWalletsAndSufficientBalance(): void
    {
        $user = (new User())->setRole(User::ROLE_CLIENT);
        $sourceWallet = $this->createWallet(10, 1, '200.00', true, false, 'Client Wallet');
        $merchantWallet = $this->createWallet(20, 2, '150.00', true, false, 'Merchant Wallet');

        $walletTransferService = $this->createMock(WalletTransferService::class);
        $walletTransferService->method('findWalletForUser')->willReturn($sourceWallet);
        $walletTransferService->method('findDestinationWallet')->willReturn($merchantWallet);

        $walletAuditService = $this->createMock(WalletAuditService::class);
        $service = new MerchantQrPaymentService($walletTransferService, $walletAuditService);

        $result = $service->preview($user, [
            'merchant_wallet_id' => '20',
            'merchant_name' => 'FinTrust Store',
            'amount' => 40,
            'reference' => 'ORDER-2026-001',
        ]);

        self::assertTrue($result['valid']);
        self::assertSame([], $result['errors']);
        self::assertSame('FinTrust Store', $result['merchant']['name']);
        self::assertSame(40.0, $result['payment']['amount']);
        self::assertFalse($result['payment']['free_amount']);
        self::assertSame(200.0, $result['source_wallet']['balance']);
    }

    private function createWallet(
        int $walletId,
        int $userId,
        string $balance,
        bool $active,
        bool $blocked,
        string $owner
    ): Wallet {
        $wallet = new Wallet();
        $wallet->setIdUser($userId);
        $wallet->setSolde($balance);
        $wallet->setEstActif($active);
        $wallet->setEstBloque($blocked);
        $wallet->setNomProprietaire($owner);
        $wallet->setDevise('TND');
        $wallet->setStatut($blocked ? 'BLOQUE' : 'ACTIF');
        $wallet->setDateCreation(new \DateTimeImmutable('2026-01-01 10:00:00'));

        $reflection = new \ReflectionProperty(Wallet::class, 'idWallet');
        $reflection->setAccessible(true);
        $reflection->setValue($wallet, $walletId);

        return $wallet;
    }
}
