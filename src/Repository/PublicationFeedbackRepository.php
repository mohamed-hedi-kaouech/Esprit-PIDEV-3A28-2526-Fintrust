<?php

namespace App\Repository;

use App\Entity\Publication\PublicationFeedback;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PublicationFeedback>
 */
class PublicationFeedbackRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PublicationFeedback::class);
    }

    public function findApprovedComments($publicationId, $page = 1, $limit = 10)
    {
        return $this->createQueryBuilder('f')
            ->where('f.publication = :pubId')
            ->andWhere('f.type = :type')
            ->andWhere('f.isApproved = true')
            ->andWhere('f.parentFeedback IS NULL')
            ->setParameter('pubId', $publicationId)
            ->setParameter('type', PublicationFeedback::TYPE_COMMENT)
            ->orderBy('f.createdAt', 'DESC')
            ->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function countApprovedComments($publicationId): int
    {
        return (int) $this->createQueryBuilder('f')
            ->select('COUNT(f.id)')
            ->where('f.publication = :pubId')
            ->andWhere('f.type = :type')
            ->andWhere('f.isApproved = true')
            ->setParameter('pubId', $publicationId)
            ->setParameter('type', PublicationFeedback::TYPE_COMMENT)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
