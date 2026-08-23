<?php

namespace App\Entity;

use App\Repository\KapNewsRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: KapNewsRepository::class)]
#[ORM\Table(name: 'kap_news')]

#[ORM\UniqueConstraint(name: 'UNIQ_KAP_ID', columns: ['kap_id'])]

#[ORM\Index(name: 'IDX_PUBLISHED_AT', columns: ['published_at'])]
class KapNews
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 50)]
    private ?string $kapId = null;

    #[ORM\Column(length: 500)]
    private ?string $title = null;

    #[ORM\Column(type: Types::TEXT)]
    private ?string $content = null;

    #[ORM\Column(type: Types::JSON)]
    private array $stockCodes = [];

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $publishedAt = null;

    #[ORM\Column(options: ['default' => false])]
    private ?bool $isAnalyzed = false;

    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    private ?int $sentimentScore = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $aiSummary = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $analyzedAt = null;

    public function __construct()
    {
        $this->publishedAt = new \DateTimeImmutable();
        $this->isAnalyzed = false;
        $this->stockCodes = [];
    }


    public function getId(): ?int
    {
        return $this->id;
    }

    public function getKapId(): ?string
    {
        return $this->kapId;
    }

    public function setKapId(string $kapId): static
    {
        $this->kapId = $kapId;
        return $this;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(string $title): static
    {
        $this->title = mb_substr($title, 0, 500);
        return $this;
    }

    public function getContent(): ?string
    {
        return $this->content;
    }

    public function setContent(string $content): static
    {
        $this->content = $content;
        return $this;
    }

    public function getStockCodes(): array
    {
        return $this->stockCodes;
    }

    public function setStockCodes(array $stockCodes): static
    {
        $normalized = [];
        foreach ($stockCodes as $stockCode) {
            $stockCode = strtoupper(trim((string) $stockCode));
            if (preg_match('/^[A-Z0-9]{2,20}$/', $stockCode)) {
                $normalized[$stockCode] = true;
            }
        }

        $this->stockCodes = array_keys($normalized);
        return $this;
    }

    public function getPublishedAt(): ?\DateTimeImmutable
    {
        return $this->publishedAt;
    }

    public function setPublishedAt(\DateTimeImmutable $publishedAt): static
    {
        $this->publishedAt = $publishedAt;
        return $this;
    }

    public function isAnalyzed(): ?bool
    {
        return $this->isAnalyzed;
    }

    public function setIsAnalyzed(bool $isAnalyzed): static
    {
        $this->isAnalyzed = $isAnalyzed;
        return $this;
    }

    public function getSentimentScore(): ?int
    {
        return $this->sentimentScore;
    }

    public function setSentimentScore(?int $sentimentScore): static
    {
        $this->sentimentScore = $sentimentScore === null ? null : max(-100, min(100, $sentimentScore));
        return $this;
    }

    public function getAiSummary(): ?string
    {
        return $this->aiSummary;
    }

    public function setAiSummary(?string $aiSummary): static
    {
        $this->aiSummary = $aiSummary;
        return $this;
    }

    public function getAnalyzedAt(): ?\DateTimeImmutable
    {
        return $this->analyzedAt;
    }

    public function setAnalyzedAt(?\DateTimeImmutable $analyzedAt): static
    {
        $this->analyzedAt = $analyzedAt;
        return $this;
    }
}
