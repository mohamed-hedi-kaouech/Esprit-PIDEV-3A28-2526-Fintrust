<?php

namespace App\Repository\Product;

use App\Entity\Product\Product;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class ProductRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Product::class);
    }

    /**
     * Find all products ordered by creation date (newest first).
     *
     * @return Product[]
     */
    public function findAllOrderedByDate(): array
    {
        return $this->createQueryBuilder('p')
            ->orderBy('p.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
    private function validateProductData($category, $price, $description): array
    {
        $errors = [];

        // Category validation
        if (!$category) {
            $errors[] = 'La catégorie est obligatoire';
        }

        // Price validation
        if (!is_numeric($price) || $price < 0) {
            $errors[] = 'Le prix doit être un nombre positif';
        }

        // Description validation
        if (strlen(trim($description)) < 4) {
            $errors[] = 'La description doit contenir au moins 4 caractères';
        }

        return $errors;
    }
    /**
     * Find products by category.
     *
     * @return Product[]
     */
    public function findByCategory(string $category): array
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.category = :cat')
            ->setParameter('cat', $category)
            ->orderBy('p.price', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Search products by description keyword.
     *
     * @return Product[]
     */
    public function searchByDescription(string $keyword): array
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.description LIKE :kw')
            ->setParameter('kw', '%' . $keyword . '%')
            ->orderBy('p.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }


    public function findFiltered($search, $category, $sort)
{
    $qb = $this->createQueryBuilder('p');

    if ($search) {
        $qb->andWhere('LOWER(p.description) LIKE :search OR LOWER(p.category) LIKE :search')
           ->setParameter('search', '%' . strtolower($search) . '%');
    }

    if ($category) {
        $qb->andWhere('p.category = :category')
           ->setParameter('category', $category);
    }

    switch ($sort) {
        case 'price_asc':
            $qb->orderBy('p.price', 'ASC');
            break;
        case 'price_desc':
            $qb->orderBy('p.price', 'DESC');
            break;
        case 'date_asc':
            $qb->orderBy('p.createdAt', 'ASC');
            break;
        case 'date_desc':
            $qb->orderBy('p.createdAt', 'DESC');
            break;
        default:
            $qb->orderBy('p.productId', 'ASC');
    }

    return $qb->getQuery()->getResult();
}
}