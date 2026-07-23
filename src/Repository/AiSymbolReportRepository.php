<?php

namespace App\Repository;

use App\Entity\AiSymbolReport;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AiSymbolReport>
 */
class AiSymbolReportRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AiSymbolReport::class);
    }

    /**
     * @return AiSymbolReport[]
     */
    public function findLatest(int $limit = 50): array
    {
        return $this->createQueryBuilder('r')
            ->orderBy('r.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * @return AiSymbolReport[]
     */
    public function findLatestDistinct(int $limit = 50, string $scope = AiSymbolReport::SCOPE_TRACKED): array
    {
        $subQuery = $this->getEntityManager()->createQueryBuilder()
            ->select('MAX(r2.id)')
            ->from(AiSymbolReport::class, 'r2')
            ->andWhere('r2.reportScope = :scope')
            ->groupBy('r2.symbol');

        return $this->createQueryBuilder('r')
            ->andWhere(sprintf('r.id IN (%s)', $subQuery->getDQL()))
            ->setParameter('scope', $scope)
            ->orderBy('r.score', 'DESC')
            ->addOrderBy('r.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * @param string[] $symbols
     * @return array<string, AiSymbolReport>
     */
    public function findLatestBySymbols(array $symbols, string $scope = AiSymbolReport::SCOPE_TRACKED): array
    {
        $symbols = array_values(array_unique(array_map(
            fn(string $symbol): string => strtoupper(trim($symbol)),
            $symbols
        )));

        if (empty($symbols)) {
            return [];
        }

        $subQuery = $this->getEntityManager()->createQueryBuilder()
            ->select('MAX(r2.id)')
            ->from(AiSymbolReport::class, 'r2')
            ->andWhere('r2.symbol IN (:symbols)')
            ->andWhere('r2.reportScope = :scope')
            ->groupBy('r2.symbol');

        $reports = $this->createQueryBuilder('r')
            ->andWhere(sprintf('r.id IN (%s)', $subQuery->getDQL()))
            ->setParameter('symbols', $symbols)
            ->setParameter('scope', $scope)
            ->orderBy('r.id', 'DESC')
            ->getQuery()
            ->getResult();

        $latest = [];
        foreach ($reports as $report) {
            if (!$report instanceof AiSymbolReport) {
                continue;
            }

            $symbol = $report->getSymbol();
            if (!isset($latest[$symbol])) {
                $latest[$symbol] = $report;
            }
        }

        return $latest;
    }

    /**
     * @return AiSymbolReport[]
     */
    public function findHistoryForSymbol(string $symbol, int $limit = 20, string $scope = AiSymbolReport::SCOPE_TRACKED): array
    {
        return $this->createQueryBuilder('r')
            ->andWhere('r.symbol = :symbol')
            ->andWhere('r.reportScope = :scope')
            ->setParameter('symbol', strtoupper(trim($symbol)))
            ->setParameter('scope', $scope)
            ->orderBy('r.createdAt', 'DESC')
            ->addOrderBy('r.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
