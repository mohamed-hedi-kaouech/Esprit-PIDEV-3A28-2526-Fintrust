<?php

namespace App\Repository\Product;

use App\Entity\Product\ProductSubscription;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ProductSubscription>
 *
 * @method ProductSubscription|null find($id, $lockMode = null, $lockVersion = null)
 * @method ProductSubscription|null findOneBy(array $criteria, array $orderBy = null)
 * @method ProductSubscription[]    findAll()
 * @method ProductSubscription[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class ProductSubscriptionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ProductSubscription::class);
    }
    public function findByFilters(?string $type, ?string $status, ?string $search)
    {
        $qb = $this->createQueryBuilder('s')
            ->join('s.clientUser', 'c')
            ->join('s.productObj', 'p');

        if (!empty($type)) {
            $qb->andWhere('s.type = :type')
                ->setParameter('type', $type);
        }

        if (!empty($status)) {
            $qb->andWhere('s.status = :status')
                ->setParameter('status', $status);
        }

        if (!empty($search)) {
            $qb->andWhere('p.category LIKE :search OR c.nom LIKE :search')
                ->setParameter('search', '%' . $search . '%');
        }

        return $qb->getQuery()->getResult();
    }
    // Example: custom query to get subscriptions expiring in 30 days
    public function findExpiringSoon(\DateTimeInterface $dateLimit): array
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.expirationDate <= :dateLimit')
            ->setParameter('dateLimit', $dateLimit)
            ->orderBy('s.expirationDate', 'ASC')
            ->getQuery()
            ->getResult();
    }

    // You can add more custom queries as needed
}