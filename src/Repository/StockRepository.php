<?php

namespace App\Repository;

use App\Entity\Stock;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Stock>
 */
class StockRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Stock::class);
    }

    public function findRecent(string $symbol, int $seconds = 30): ?Stock
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.symbol = :symbol')
            ->andWhere('s.createdAt >= :time')
            ->setParameter('symbol', $symbol)
            ->setParameter('time', new \DateTime("-{$seconds} seconds"))
            ->orderBy('s.createdAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findLatest(string $symbol): ?Stock
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.symbol = :symbol')
            ->setParameter('symbol', strtoupper(trim($symbol)))
            ->orderBy('s.createdAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @param string[] $symbols
     * @return array<string, Stock>
     */
    public function findLatestForSymbols(array $symbols): array
    {
        if (empty($symbols)) {
            return [];
        }

        $stocks = $this->createQueryBuilder('s')
            ->andWhere('s.symbol IN (:symbols)')
            ->setParameter('symbols', $symbols)
            ->andWhere('s.createdAt >= :time')
            ->setParameter('time', new \DateTime('-15 minutes'))
            ->orderBy('s.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
        
        $results = [];
        foreach ($stocks as $s) {
            if (!isset($results[$s->getSymbol()])) {
                $results[$s->getSymbol()] = $s;
            }
        }
        
        return $results;
    }
}
