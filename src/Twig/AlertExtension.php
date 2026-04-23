<?php

namespace App\Twig;

use App\Entity\Categorie\Alerte;
use Doctrine\ORM\EntityManagerInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class AlertExtension extends AbstractExtension
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('active_alerts_count', [$this, 'getActiveAlertsCount']),
        ];
    }

    public function getActiveAlertsCount(): int
    {
        return $this->entityManager->getRepository(Alerte::class)
            ->count(['active' => true]);
    }
}