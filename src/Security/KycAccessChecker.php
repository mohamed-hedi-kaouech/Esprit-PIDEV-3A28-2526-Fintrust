<?php

namespace App\Security;

use App\Entity\User\User;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Service de garde KYC.
 *
 * Vérifie que l'utilisateur possède un KYC approuvé avant d'accéder
 * aux fonctionnalités sensibles (dashboard, etc.).
 *
 * Usage dans un contrôleur :
 *   if ($redirect = $this->kycAccessChecker->check($user)) {
 *       return $redirect;
 *   }
 */
class KycAccessChecker
{
    public function __construct(private readonly UrlGeneratorInterface $urlGenerator) {}

    /**
     * Retourne une RedirectResponse vers la page de statut KYC si l'accès doit être bloqué.
     * Retourne null si l'utilisateur est autorisé.
     */
    public function check(User $user): ?Response
    {
        if (!$user->isKycApproved()) {
            return new RedirectResponse($this->urlGenerator->generate('front_kyc_status'));
        }

        return null;
    }
}
