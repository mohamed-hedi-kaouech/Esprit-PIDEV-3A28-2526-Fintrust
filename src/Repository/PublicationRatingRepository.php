<?php

namespace App\Repository;

use App\Entity\Publication\PublicationRating;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PublicationRating>
 */
class PublicationRatingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PublicationRating::class);
    }

    public function getAverageRating($publicationId)
    {
        return $this->createQueryBuilder('r')
            ->select('AVG(r.rating) as avg_rating, COUNT(r.id) as total')
            ->where('r.publication = :pubId')
            ->setParameter('pubId', $publicationId)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
