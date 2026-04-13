<?php

namespace App\Tests\Entity\User;

use App\Entity\User\User;
use PHPUnit\Framework\TestCase;

class UserTest extends TestCase
{
    public function testAdminRolesAreReturnedCorrectly(): void
    {
        $user = (new User())
            ->setRole(User::ROLE_ADMIN);

        self::assertSame(['ROLE_ADMIN', 'ROLE_USER'], $user->getRoles());
        self::assertTrue($user->isAdmin());
    }

    public function testClientRolesAreReturnedCorrectly(): void
    {
        $user = (new User())
            ->setRole(User::ROLE_CLIENT);

        self::assertSame(['ROLE_CLIENT', 'ROLE_USER'], $user->getRoles());
        self::assertFalse($user->isAdmin());
    }

    public function testFullNameAndStatusHelpers(): void
    {
        $user = (new User())
            ->setPrenom('Ines')
            ->setNom('Hmani')
            ->setStatus(User::STATUS_ACTIF)
            ->setKycStatus(User::KYC_APPROUVE);

        self::assertSame('Ines Hmani', $user->getFullName());
        self::assertTrue($user->isActive());
        self::assertTrue($user->isKycApproved());
    }

    public function testEngagementBadgeReturnsVipToneForTopProfile(): void
    {
        $user = (new User())
            ->setStatus(User::STATUS_ACTIF)
            ->setKycStatus(User::KYC_APPROUVE)
            ->setClientSegment(User::SEGMENT_VIP)
            ->setTransactionFrequency(1.4)
            ->setAverageTransactionAmount(3200);

        self::assertSame('ROI FINTRUST', $user->getEngagementBadge());
        self::assertSame('royal', $user->getEngagementBadgeTone());
    }

    public function testAtRiskHelperUsesRiskLevelOrSegment(): void
    {
        $highRiskUser = (new User())
            ->setRiskLevel(User::RISK_HIGH)
            ->setClientSegment(User::SEGMENT_STANDARD);

        $segmentedUser = (new User())
            ->setRiskLevel(User::RISK_LOW)
            ->setClientSegment(User::SEGMENT_AT_RISK);

        self::assertTrue($highRiskUser->isAtRisk());
        self::assertTrue($segmentedUser->isAtRisk());
        self::assertFalse((new User())->isCriticalRisk());
    }
}
