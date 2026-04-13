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