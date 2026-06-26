<?php

namespace App\Repository;

use App\Entity\WatchlistItem;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<WatchlistItem>
 */
class WatchlistItemRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WatchlistItem::class);
    }

    /**
     * @return WatchlistItem[]
     */
    public function findOrdered(): array
    {
        return $this->createQueryBuilder('w')
            ->orderBy('w.isActive', 'DESC')
            ->addOrderBy('w.symbol', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return WatchlistItem[]
     */
    public function findActive(): array
    {
        return $this->createQueryBuilder('w')
            ->andWhere('w.isActive = true')
            ->orderBy('w.symbol', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findOneBySymbol(string $symbol): ?WatchlistItem
    {
        return $this->findOneBy(['symbol' => strtoupper(trim($symbol))]);
    }
}
