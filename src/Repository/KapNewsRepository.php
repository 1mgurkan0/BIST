<?php

namespace App\Repository;

use App\Entity\KapNews;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<KapNews>
 */
class KapNewsRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, KapNews::class);
    }

//    /**
//     * @return KapNews[] Returns an array of KapNews objects
//     */
//    public function findByExampleField($value): array
//    {
//        return $this->createQueryBuilder('k')
//            ->andWhere('k.exampleField = :val')
//            ->setParameter('val', $value)
//            ->orderBy('k.id', 'ASC')
//            ->setMaxResults(10)
//            ->getQuery()
//            ->getResult()
//        ;
//    }

//    public function findOneBySomeField($value): ?KapNews
//    {
//        return $this->createQueryBuilder('k')
//            ->andWhere('k.exampleField = :val')
//            ->setParameter('val', $value)
//            ->getQuery()
//            ->getOneOrNullResult()
//        ;
//    }

    public function findUnanalyzedNews(int $limit = 10): array
    {
        return $this->createQueryBuilder('k')
            ->andWhere('k.isAnalyzed = :status')
            ->setParameter('status', false)
            ->orderBy('k.publishedAt', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * @param string[]|null $symbols Null means all BIST news.
     * @return KapNews[]
     */
    public function findUnanalyzedForSymbols(?array $symbols, int $limit = 10): array
    {
        $qb = $this->createQueryBuilder('k')
            ->andWhere('k.isAnalyzed = false')
            ->orderBy('k.publishedAt', 'DESC')
            ->setMaxResults(max(1, $limit));

        if ($symbols !== null) {
            $symbols = array_values(array_unique(array_filter(array_map(
                static function (string $symbol): ?string {
                    $symbol = strtoupper(trim($symbol));
                    return preg_match('/^[A-Z0-9]{2,20}$/', $symbol) ? $symbol : null;
                },
                $symbols,
            ))));

            if ($symbols === []) {
                return [];
            }

            $conditions = $qb->expr()->orX();
            foreach ($symbols as $index => $symbol) {
                $parameter = 'trackedSymbol' . $index;
                $conditions->add($qb->expr()->like('k.stockCodes', ':' . $parameter));
                $qb->setParameter($parameter, '%"' . $symbol . '"%');
            }
            $qb->andWhere($conditions);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * @return KapNews[]
     */
    public function findRecentForSymbol(string $symbol, \DateTimeImmutable $since, int $limit = 5): array
    {
        $symbol = strtoupper(trim($symbol));

        return $this->createQueryBuilder('k')
            ->andWhere('k.stockCodes LIKE :symbol')
            ->andWhere('k.publishedAt >= :since')
            ->setParameter('symbol', '%"' . $symbol . '"%')
            ->setParameter('since', $since)
            ->orderBy('k.publishedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * @param string[] $kapIds
     * @return string[]
     */
    public function findExistingKapIds(array $kapIds): array
    {
        if (empty($kapIds)) {
            return [];
        }

        $rows = $this->createQueryBuilder('k')
            ->select('k.kapId')
            ->andWhere('k.kapId IN (:kapIds)')
            ->setParameter('kapIds', array_values(array_unique($kapIds)))
            ->getQuery()
            ->getScalarResult();

        return array_values(array_map(
            static fn(array $row): string => (string) $row['kapId'],
            $rows
        ));
    }
}
