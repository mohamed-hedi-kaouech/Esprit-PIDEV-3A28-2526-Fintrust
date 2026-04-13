<?php

namespace App\Security;

use App\Entity\User\User;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class RiskAccessChecker
{
    public function __construct(
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {}

    public function checkSensitiveModule(User $user): ?Response
    {
        if ($user->isCriticalRisk()) {
            return new RedirectResponse($this->urlGenerator->generate('front_dashboard'));
        }

        return null;
    }
}
