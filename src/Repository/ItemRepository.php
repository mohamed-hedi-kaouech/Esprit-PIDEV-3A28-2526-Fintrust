<?php

namespace App\Repository;

use App\Entity\Categorie\Item;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Item>
 *
 * @method Item|null find($id, $lockMode = null, $lockVersion = null)
 * @method Item|null findOneBy(array $criteria, array $orderBy = null)
 * @method Item[]    findAll()
 * @method Item[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class ItemRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Item::class);
    }

    public function add(Item $entity, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(Item $entity, bool $flush = false): void
    {
        $this->getEntityManager()->remove($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function getTotalMontantByCategorie(int $categorieId, ?int $excludeItemId = null): float
    {
        $qb = $this->createQueryBuilder('i')
            ->select('COALESCE(SUM(i.montant), 0)')
            ->andWhere('i.idCategorie = :categorieId')
            ->setParameter('categorieId', $categorieId);

        if ($excludeItemId !== null) {
            $qb->andWhere('i.idItem != :excludeItemId')
               ->setParameter('excludeItemId', $excludeItemId);
        }

        return (float) $qb->getQuery()->getSingleScalarResult();
    }
}
