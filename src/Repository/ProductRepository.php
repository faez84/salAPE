<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Product;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Product>
 */
class ProductRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Product::class);
    }


    /**
     * @param array<string> $ids
     * @return array<object>
     */
    public function findInValues(array $ids): array
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.id in (:ids)')
            ->setParameter('ids', value: $ids)
            ->orderBy('p.id', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
