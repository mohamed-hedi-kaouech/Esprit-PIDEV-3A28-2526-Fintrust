<?php

namespace App\Repository;

use App\Entity\Publication\Publication;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Publication>
 */
class PublicationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Publication::class);
    }

    public function findPublishedWithStats(
        ?string $categoryName = null,
        ?string $searchTerm = null,
        ?string $sortBy = 'recentes',
        int $page = 1,
        int $limit = 10
    ) {
        $qb = $this->createQueryBuilder('p')
            ->leftJoin('p.feedbacks', 'f')
            ->addSelect('p')
            ->addSelect('COUNT(f.idFeedback) AS HIDDEN commentCount')
            ->addSelect('SUM(CASE WHEN f.typeReaction = :likeType THEN 1 ELSE 0 END) AS HIDDEN likeCount')
            ->setParameter('likeType', 'LIKE')
            ->where('p.statut = :status')
            ->setParameter('status', Publication::STATUS_PUBLIE);

        if ($categoryName) {
            $qb->andWhere('p.categorie = :category')
                ->setParameter('category', $categoryName);
        }

        if ($searchTerm) {
            $qb->andWhere('p.titre LIKE :search OR p.contenu LIKE :search')
                ->setParameter('search', '%' . $searchTerm . '%');
        }

        match ($sortBy) {
            'trending' => $qb->orderBy('commentCount', 'DESC')->addOrderBy('p.datePublication', 'DESC'),
            'mieux_notees' => $qb->orderBy('likeCount', 'DESC')->addOrderBy('commentCount', 'DESC'),
            default => $qb->orderBy('p.datePublication', 'DESC'),
        };

        return $qb
            ->groupBy('p.id')
            ->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function countPublished(?string $categoryName = null, ?string $searchTerm = null): int
    {
        $qb = $this->createQueryBuilder('p')
            ->select('COUNT(DISTINCT p.id)')
            ->where('p.statut = :status')
            ->setParameter('status', Publication::STATUS_PUBLIE);

        if ($categoryName) {
            $qb->andWhere('p.categorie = :category')
                ->setParameter('category', $categoryName);
        }

        if ($searchTerm) {
            $qb->andWhere('p.titre LIKE :search OR p.contenu LIKE :search')
                ->setParameter('search', '%' . $searchTerm . '%');
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    public function findMostViewedByCategory(int $limit = 5)
    {
        return $this->createQueryBuilder('p')
            ->select('p.categorie, COUNT(p.id) as pub_count')
            ->where('p.statut = :status')
            ->setParameter('status', Publication::STATUS_PUBLIE)
            ->groupBy('p.categorie')
            ->orderBy('pub_count', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function findTopRated(int $limit = 5)
    {
        return $this->createQueryBuilder('p')
            ->where('p.statut = :status')
            ->setParameter('status', Publication::STATUS_PUBLIE)
            ->orderBy('p.datePublication', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function getDistinctCategories(): array
    {
        $rows = $this->createQueryBuilder('p')
            ->select('DISTINCT p.categorie AS categorie')
            ->orderBy('p.categorie', 'ASC')
            ->getQuery()
            ->getResult();

        return array_values(array_filter(array_map(fn(array $row) => $row['categorie'], $rows), fn($value) => $value !== null && $value !== ''));
    }

    public function getCategoryCounts(): array
    {
        return $this->createQueryBuilder('p')
            ->select('p.categorie AS category, COUNT(p.id) AS count')
            ->where('p.categorie IS NOT NULL')
            ->andWhere("p.categorie <> ''")
            ->groupBy('p.categorie')
            ->orderBy('count', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function createFilteredQueryBuilder(?string $search = null, ?string $categoryName = null, ?string $status = null)
    {
        $qb = $this->createQueryBuilder('p');

        if ($status) {
            $qb->andWhere('p.statut = :status')
                ->setParameter('status', $status);
        }

        if ($categoryName) {
            $qb->andWhere('p.categorie = :category')
                ->setParameter('category', $categoryName);
        }

        if ($search) {
            $qb->andWhere('p.titre LIKE :search OR p.contenu LIKE :search')
                ->setParameter('search', '%' . $search . '%');
        }

        return $qb;
    }
}
