<?php

namespace App\Tests\Security;

use App\Entity\User\User;
use App\Security\KycAccessChecker;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class KycAccessCheckerTest extends TestCase
{
    public function testCheckReturnsNullForApprovedUser(): void
    {
        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $checker = new KycAccessChecker($urlGenerator);

        $user = (new User())->setKycStatus(User::KYC_APPROUVE);

        self::assertNull($checker->check($user));
    }

    public function testCheckRedirectsWhenKycIsNotApproved(): void
    {
        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator
            ->expects(self::once())
            ->method('generate')
            ->with('front_kyc_status')
            ->willReturn('/espace-client/kyc/status');

        $checker = new KycAccessChecker($urlGenerator);
        $user = (new User())->setKycStatus(User::KYC_EN_ATTENTE);

        $response = $checker->check($user);

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame('/espace-client/kyc/status', $response->getTargetUrl());
    }
}
