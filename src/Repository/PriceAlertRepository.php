<?php

namespace App\Repository;

use App\Entity\PriceAlert;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PriceAlert>
 */
class PriceAlertRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PriceAlert::class);
    }

    /**
     * @return PriceAlert[]
     */
    public function findOrdered(): array
    {
        return $this->createQueryBuilder('a')
            ->orderBy('a.isActive', 'DESC')
            ->addOrderBy('a.isTriggered', 'DESC')
            ->addOrderBy('a.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return PriceAlert[]
     */
    public function findActive(?string $symbol = null): array
    {
        $qb = $this->createQueryBuilder('a')
            ->andWhere('a.isActive = true')
            ->andWhere('a.isTriggered = false')
            ->orderBy('a.symbol', 'ASC')
            ->addOrderBy('a.createdAt', 'ASC');

        if ($symbol !== null) {
            $qb
                ->andWhere('a.symbol = :symbol')
                ->setParameter('symbol', strtoupper(trim($symbol)));
        }

        return $qb->getQuery()->getResult();
    }
}
