<?php

namespace App\Repository;

use App\Entity\OpportunityCandidate;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<OpportunityCandidate> */
class OpportunityCandidateRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, OpportunityCandidate::class);
    }

    /** @return OpportunityCandidate[] */
    public function findLatestBatch(bool $eligibleOnly = false, ?int $limit = null): array
    {
        $latest = $this->findOneBy([], ['id' => 'DESC']);
        if (!$latest instanceof OpportunityCandidate) {
            return [];
        }

        $qb = $this->createQueryBuilder('candidate')
            ->andWhere('candidate.batchId = :batchId')
            ->setParameter('batchId', $latest->getBatchId())
            ->orderBy('candidate.rank', 'ASC')
            ->addOrderBy('candidate.score', 'DESC')
            ->addOrderBy('candidate.symbol', 'ASC');

        if ($eligibleOnly) {
            $qb->andWhere('candidate.status = :status')
                ->setParameter('status', OpportunityCandidate::STATUS_ELIGIBLE);
        }

        if ($limit !== null) {
            $qb->setMaxResults(max(1, $limit));
        }

        return $qb->getQuery()->getResult();
    }

    /** @return string[] */
    public function latestEligibleSymbols(int $limit = 5): array
    {
        return array_map(
            fn(OpportunityCandidate $candidate): string => $candidate->getSymbol(),
            $this->findLatestBatch(true, $limit)
        );
    }
}
