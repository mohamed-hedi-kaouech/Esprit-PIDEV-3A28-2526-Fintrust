<?php

namespace App\Service;

use App\Entity\Categorie\Alerte;
use Doctrine\ORM\EntityManagerInterface;

class AlertService
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function getActiveAlertsCount(): int
    {
        return $this->entityManager->getRepository(Alerte::class)
            ->count(['active' => true]);
    }
}