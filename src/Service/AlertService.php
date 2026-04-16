<?php

namespace App\Service;

use App\Entity\Categorie\Alerte;
use Doctrine\ORM\EntityManagerInterface;
use Throwable;

class AlertService
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function getActiveAlertsCount(): int
    {
        try {
            $schemaManager = $this->entityManager->getConnection()->createSchemaManager();
            $columns = $schemaManager->listTableColumns('alerte');
            $hasReadStatus = array_key_exists('read_status', $columns);

            return $this->entityManager->getRepository(Alerte::class)
                ->count($hasReadStatus ? ['active' => true, 'read' => false] : ['active' => true]);
        } catch (Throwable) {
            return $this->entityManager->getRepository(Alerte::class)
                ->count(['active' => true]);
        }
    }
}
