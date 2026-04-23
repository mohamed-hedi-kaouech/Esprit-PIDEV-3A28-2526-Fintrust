<?php

namespace App\Tests\Security;

use App\Entity\User\User;
use App\Security\RiskAccessChecker;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class RiskAccessCheckerTest extends TestCase
{
    public function testCheckSensitiveModuleReturnsNullForSafeUser(): void
    {
        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $checker = new RiskAccessChecker($urlGenerator);

        $user = (new User())->setRiskLevel(User::RISK_LOW);

        self::assertNull($checker->checkSensitiveModule($user));
    }

    public function testCheckSensitiveModuleRedirectsCriticalRiskUser(): void
    {
        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator
            ->expects(self::once())
            ->method('generate')
            ->with('front_dashboard')
            ->willReturn('/espace-client/tableau-de-bord');

        $checker = new RiskAccessChecker($urlGenerator);
        $user = (new User())->setRiskLevel(User::RISK_CRITICAL);

        $response = $checker->checkSensitiveModule($user);

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame('/espace-client/tableau-de-bord', $response->getTargetUrl());
    }
}
