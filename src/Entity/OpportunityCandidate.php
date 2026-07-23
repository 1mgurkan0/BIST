<?php

namespace App\Entity;

use App\Repository\OpportunityCandidateRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: OpportunityCandidateRepository::class)]
#[ORM\Table(name: 'opportunity_candidate')]
#[ORM\Index(name: 'IDX_OPPORTUNITY_BATCH_RANK', columns: ['batch_id', 'candidate_rank'])]
#[ORM\Index(name: 'IDX_OPPORTUNITY_SYMBOL_CREATED', columns: ['symbol', 'created_at'])]
#[ORM\HasLifecycleCallbacks]
class OpportunityCandidate
{
    public const STATUS_ELIGIBLE = 'eligible';
    public const STATUS_STALE = 'stale';
    public const STATUS_MISSING = 'missing';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 32)]
    private string $batchId;

    #[ORM\Column(length: 20)]
    private string $symbol;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $scanDate;

    #[ORM\Column(type: Types::SMALLINT)]
    private int $score = 0;

    #[ORM\Column(name: 'candidate_rank', type: Types::SMALLINT, nullable: true)]
    private ?int $rank = null;

    #[ORM\Column(length: 20)]
    private string $status = self::STATUS_MISSING;

    #[ORM\Column(length: 40)]
    private string $historyStatus = 'missing_history';

    #[ORM\Column(options: ['default' => false])]
    private bool $isHistoryStale = false;

    #[ORM\Column(type: Types::JSON)]
    private array $technicalSnapshot = [];

    #[ORM\Column(type: Types::JSON)]
    private array $reasons = [];

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $createdAt = null;

    public function __construct()
    {
        $this->scanDate = new \DateTimeImmutable('today');
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getBatchId(): string
    {
        return $this->batchId;
    }

    public function setBatchId(string $batchId): static
    {
        $this->batchId = substr(trim($batchId), 0, 32);
        return $this;
    }

    public function getSymbol(): string
    {
        return $this->symbol;
    }

    public function setSymbol(string $symbol): static
    {
        $this->symbol = strtoupper(trim($symbol));
        return $this;
    }

    public function getScanDate(): \DateTimeImmutable
    {
        return $this->scanDate;
    }

    public function setScanDate(\DateTimeImmutable $scanDate): static
    {
        $this->scanDate = $scanDate;
        return $this;
    }

    public function getScore(): int
    {
        return $this->score;
    }

    public function setScore(int $score): static
    {
        $this->score = max(0, min(100, $score));
        return $this;
    }

    public function getRank(): ?int
    {
        return $this->rank;
    }

    public function setRank(?int $rank): static
    {
        $this->rank = $rank;
        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        $this->status = in_array($status, [self::STATUS_ELIGIBLE, self::STATUS_STALE, self::STATUS_MISSING], true)
            ? $status
            : self::STATUS_MISSING;
        return $this;
    }

    public function getHistoryStatus(): string
    {
        return $this->historyStatus;
    }

    public function setHistoryStatus(string $historyStatus): static
    {
        $this->historyStatus = substr(trim($historyStatus), 0, 40) ?: 'missing_history';
        return $this;
    }

    public function isHistoryStale(): bool
    {
        return $this->isHistoryStale;
    }

    public function setIsHistoryStale(bool $isHistoryStale): static
    {
        $this->isHistoryStale = $isHistoryStale;
        return $this;
    }

    public function getTechnicalSnapshot(): array
    {
        return $this->technicalSnapshot;
    }

    public function setTechnicalSnapshot(array $technicalSnapshot): static
    {
        $this->technicalSnapshot = $technicalSnapshot;
        return $this;
    }

    public function getReasons(): array
    {
        return $this->reasons;
    }

    public function setReasons(array $reasons): static
    {
        $this->reasons = array_values(array_filter($reasons, 'is_string'));
        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        $this->createdAt ??= new \DateTimeImmutable();
    }
}
