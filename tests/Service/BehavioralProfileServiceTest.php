<?php

namespace App\Tests\Service;

use App\Entity\User\User;
use App\Entity\Wallet\Transaction;
use App\Entity\Wallet\Wallet;
use App\Service\BehavioralProfileService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;

class BehavioralProfileServiceTest extends TestCase
{
    public function testBuildProfileReturnsLowRiskProfileWithoutTransactions(): void
    {
        $user = (new User())
            ->setStatus(User::STATUS_ACTIF)
            ->setKycStatus(User::KYC_APPROUVE);

        $service = new BehavioralProfileService($this->createEntityManagerReturningWallets([]));

        $profile = $service->buildProfile($user);

        self::assertSame(0.0, $profile['transactionFrequency']);
        self::assertSame(0.0, $profile['averageTransactionAmount']);
        self::assertSame(User::RISK_LOW, $profile['riskLevel']);
        self::assertSame(User::SEGMENT_STANDARD, $profile['clientSegment']);
    }

    public function testBuildProfileReturnsVipProfileForHealthyHighValueUser(): void
    {
        $user = (new User())
            ->setStatus(User::STATUS_ACTIF)
            ->setKycStatus(User::KYC_APPROUVE);

        $wallet = new Wallet();
        foreach ([2800, 3100, 2650, 2900, 3600, 2700, 2500, 3300] as $index => $amount) {
            $wallet->addTransaction(
                $this->createTransaction($amount, sprintf('-%d days 10:00', $index + 1))
            );
        }

        $service = new BehavioralProfileService($this->createEntityManagerReturningWallets([$wallet]));

        $profile = $service->buildProfile($user);

        self::assertGreaterThanOrEqual(0.25, $profile['transactionFrequency']);
        self::assertGreaterThanOrEqual(2500, $profile['averageTransactionAmount']);
        self::assertSame(User::SEGMENT_VIP, $profile['clientSegment']);
        self::assertContains($profile['riskLevel'], [User::RISK_LOW, User::RISK_MEDIUM]);
    }

    public function testBuildProfileReturnsCriticalRiskForSuspendedUserWithSuspiciousBehavior(): void
    {
        $user = (new User())
            ->setStatus(User::STATUS_SUSPENDU)
            ->setKycStatus(User::KYC_EN_ATTENTE);

        $wallet = new Wallet();
        foreach ([6200, 7100, 8700, 9300, 5800] as $index => $amount) {
            $wallet->addTransaction(
                $this->createTransaction($amount, sprintf('-%d days 02:15', $index + 1))
            );
        }

        $service = new BehavioralProfileService($this->createEntityManagerReturningWallets([$wallet]));

        $profile = $service->buildProfile($user);

        self::assertGreaterThanOrEqual(70, $profile['fraudScore']);
        self::assertGreaterThanOrEqual(85, $profile['riskScore']);
        self::assertSame(User::RISK_CRITICAL, $profile['riskLevel']);
        self::assertSame(User::SEGMENT_AT_RISK, $profile['clientSegment']);
    }

    public function testRefreshUserBehaviorUpdatesUserAndFlushesWhenRequested(): void
    {
        $user = (new User())
            ->setStatus(User::STATUS_ACTIF)
            ->setKycStatus(User::KYC_APPROUVE);

        $wallet = new Wallet();
        $wallet->addTransaction($this->createTransaction(1200, '-2 days 11:00'));

        $repository = $this->createMock(EntityRepository::class);
        $repository
            ->expects(self::once())
            ->method('findBy')
            ->with(['user' => $user])
            ->willReturn([$wallet]);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('getRepository')->with(Wallet::class)->willReturn($repository);
        $em->expects(self::once())->method('flush');

        $service = new BehavioralProfileService($em);
        $service->refreshUserBehavior($user, true);

        self::assertGreaterThan(0, $user->getAverageTransactionAmount());
        self::assertNotNull($user->getBehaviorUpdatedAt());
    }

    private function createEntityManagerReturningWallets(array $wallets): EntityManagerInterface
    {
        $repository = $this->createMock(EntityRepository::class);
        $repository
            ->expects(self::once())
            ->method('findBy')
            ->willReturn($wallets);

        $em = $this->createMock(EntityManagerInterface::class);
        $em
            ->expects(self::once())
            ->method('getRepository')
            ->with(Wallet::class)
            ->willReturn($repository);

        return $em;
    }

    private function createTransaction(float $amount, string $relativeDate): Transaction
    {
        return (new Transaction())
            ->setMontant($amount)
            ->setType('DEBIT')
            ->setDateTransaction(new \DateTimeImmutable($relativeDate));
    }
}
