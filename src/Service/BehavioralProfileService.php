<?php

namespace App\Service;

use App\Entity\User\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Service conserve pour compatibilite applicative.
 *
 * La base actuelle ne stocke pas les champs comportementaux avances du profil
 * utilisateur. On expose donc un service neutre qui ne persiste plus ces
 * indicateurs.
 */
class BehavioralProfileService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}

    public function refreshUserBehavior(User $user, bool $flush = true): void
    {
        if ($flush) {
            $this->em->flush();
        }
    }

    /**
     * @return array{
     *   transactionFrequency: float,
     *   averageTransactionAmount: float,
     *   fraudScore: float,
     *   riskScore: float,
     *   riskLevel: string,
     *   clientSegment: string
     * }
     */
    public function buildProfile(User $user): array
    {
        return [
            'transactionFrequency' => 0.0,
            'averageTransactionAmount' => 0.0,
            'fraudScore' => $user->getFraudScore(),
            'riskScore' => $user->getRiskScore(),
            'riskLevel' => $user->getRiskLevel(),
            'clientSegment' => $user->getClientSegment(),
        ];
    }
}
