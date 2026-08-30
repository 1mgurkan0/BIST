<?php

namespace App\Repository;

use App\Entity\AiSymbolHistory;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AiSymbolHistory>
 */
class AiSymbolHistoryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AiSymbolHistory::class);
    }

    public function findLatestBeforeDate(string $symbol, \DateTimeInterface $date): ?AiSymbolHistory
    {
        return $this->createQueryBuilder('a')
            ->andWhere('a.symbol = :symbol')
            ->andWhere('a.recordDate < :date')
            ->setParameter('symbol', $symbol)
            ->setParameter('date', $date->format('Y-m-d'))
            ->orderBy('a.recordDate', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}