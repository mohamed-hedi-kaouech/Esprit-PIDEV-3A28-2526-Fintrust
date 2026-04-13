<?php

namespace App\Repository;

use App\Entity\Categorie\Categorie;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Query\Expr\Join;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Categorie>
 *
 * @method Categorie|null find($id, $lockMode = null, $lockVersion = null)
 * @method Categorie|null findOneBy(array $criteria, array $orderBy = null)
 * @method Categorie[]    findAll()
 * @method Categorie[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class CategorieRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Categorie::class);
    }

    public function add(Categorie $entity, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(Categorie $entity, bool $flush = false): void
    {
        $this->getEntityManager()->remove($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function searchByFilters(
        ?string $keyword,
        ?string $status,
        ?string $budgetRange,
        ?string $itemsRange,
        string $sort
    ): array {
        $qb = $this->createQueryBuilder('c')
            ->leftJoin('c.items', 'i')
            ->leftJoin('c.alertes', 'a', Join::WITH, 'a.active = true')
            ->addSelect('i', 'a')
            ->groupBy('c.idCategorie');

        $totalSpentExpr = 'COALESCE(SUM(i.montant), 0)';
        $itemsCountExpr = 'COUNT(DISTINCT i.idItem)';
        $alertsCountExpr = 'COUNT(DISTINCT a.idAlerte)';
        $usageExpr = '(CASE WHEN c.budgetPrevu > 0 THEN (' . $totalSpentExpr . ' / c.budgetPrevu) * 100 ELSE 0 END)';

        if ($keyword !== null && $keyword !== '') {
            $qb->andWhere('c.nomCategorie LIKE :keyword')
                ->setParameter('keyword', '%' . $keyword . '%');
        }

        if ($status === 'danger') {
            $qb->having($totalSpentExpr . ' >= c.seuilAlerte OR ' . $alertsCountExpr . ' > 0');
        } elseif ($status === 'ok') {
            $qb->having($totalSpentExpr . ' < (c.seuilAlerte * 0.85) AND ' . $alertsCountExpr . ' = 0');
        } elseif ($status === 'warning') {
            $qb->having($totalSpentExpr . ' >= (c.seuilAlerte * 0.85) AND ' . $totalSpentExpr . ' < c.seuilAlerte AND ' . $alertsCountExpr . ' = 0');
        }

        if ($budgetRange === '0-500') {
            $qb->andWhere('c.budgetPrevu < 500');
        } elseif ($budgetRange === '500-1000') {
            $qb->andWhere('c.budgetPrevu >= 500 AND c.budgetPrevu < 1000');
        } elseif ($budgetRange === '1000+') {
            $qb->andWhere('c.budgetPrevu >= 1000');
        }

        if ($itemsRange === '0') {
            $qb->andHaving($itemsCountExpr . ' = 0');
        } elseif ($itemsRange === '1-5') {
            $qb->andHaving($itemsCountExpr . ' BETWEEN 1 AND 5');
        } elseif ($itemsRange === '6+') {
            $qb->andHaving($itemsCountExpr . ' >= 6');
        }

        switch ($sort) {
            case 'budget':
                $qb->orderBy('c.budgetPrevu', 'DESC');
                break;
            case 'depenses':
                $qb->orderBy($totalSpentExpr, 'DESC');
                break;
            case 'usage':
                $qb->orderBy($usageExpr, 'DESC');
                break;
            case 'items':
                $qb->orderBy($itemsCountExpr, 'DESC');
                break;
            case 'creation':
                $qb->orderBy('c.idCategorie', 'DESC');
                break;
            case 'nom':
            default:
                $qb->orderBy('c.nomCategorie', 'ASC');
                break;
        }

        return $qb->getQuery()->getResult();
    }
}
