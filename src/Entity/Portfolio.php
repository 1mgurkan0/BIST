<?php

namespace App\Entity;

use App\Repository\PortfolioRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PortfolioRepository::class)]
class Portfolio
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $symbol = null;

    #[ORM\Column]
    private ?int $lot = null;

    #[ORM\Column]
    private ?float $costPrice = null;

    #[ORM\Column(nullable: true)]
    private ?float $currentPrice = null;

    #[ORM\Column]
    private ?\DateTime $transactionDate = null;

    #[ORM\Column(nullable: true)]
    private ?float $dailyChange = null;

    #[ORM\Column(nullable: true)]
    private ?float $dailyChangePercent = null;

    #[ORM\Column(nullable: true)]
    private ?float $totalValue = null;

    #[ORM\Column(nullable: true)]
    private ?float $profitLoss = null;

    #[ORM\Column(nullable: true)]
    private ?float $profitLossPercent = null;

    #[ORM\Column(nullable: true)]
    private ?int $sentimentScore = null;
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $aiSummary = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTime $lastUpdated = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSymbol(): ?string
    {
        return $this->symbol;
    }

    public function setSymbol(string $symbol): static
    {
        $symbol = strtoupper(trim($symbol));
        if (!preg_match('/^[A-Z0-9]{2,20}$/', $symbol)) {
            throw new \InvalidArgumentException('Gecersiz BIST sembolu.');
        }
        $this->symbol = $symbol;
        return $this;
    }

    public function getLot(): ?int
    {
        return $this->lot;
    }

    public function setLot(int $lot): static
    {
        if ($lot <= 0) {
            throw new \InvalidArgumentException('Lot sifirdan buyuk olmali.');
        }
        $this->lot = $lot;
        return $this;
    }

    public function getCostPrice(): ?float
    {
        return $this->costPrice;
    }

    public function setCostPrice(float $costPrice): static
    {
        if ($costPrice <= 0.0) {
            throw new \InvalidArgumentException('Maliyet sifirdan buyuk olmali.');
        }
        $this->costPrice = $costPrice;
        return $this;
    }

    public function getCurrentPrice(): ?float
    {
        return $this->currentPrice;
    }

    public function setCurrentPrice(?float $currentPrice): static
    {
        $this->currentPrice = $currentPrice;
        return $this;
    }

    public function getTransactionDate(): ?\DateTime
    {
        return $this->transactionDate;
    }

    public function setTransactionDate(\DateTime $transactionDate): static
    {
        $this->transactionDate = $transactionDate;
        return $this;
    }

    public function getDailyChange(): ?float
    {
        return $this->dailyChange;
    }

    public function setDailyChange(?float $dailyChange): static
    {
        $this->dailyChange = $dailyChange;
        return $this;
    }

    public function getDailyChangePercent(): ?float
    {
        return $this->dailyChangePercent;
    }

    public function setDailyChangePercent(?float $dailyChangePercent): static
    {
        $this->dailyChangePercent = $dailyChangePercent;
        return $this;
    }

    public function getTotalValue(): ?float
    {
        return $this->totalValue;
    }

    public function setTotalValue(?float $totalValue): static
    {
        $this->totalValue = $totalValue;
        return $this;
    }

    public function getProfitLoss(): ?float
    {
        return $this->profitLoss;
    }

    public function setProfitLoss(?float $profitLoss): static
    {
        $this->profitLoss = $profitLoss;
        return $this;
    }

    public function getProfitLossPercent(): ?float
    {
        return $this->profitLossPercent;
    }

    public function setProfitLossPercent(?float $profitLossPercent): static
    {
        $this->profitLossPercent = $profitLossPercent;
        return $this;
    }

    public function getSentimentScore(): ?int
    {
        return $this->sentimentScore;
    }

    public function setSentimentScore(?int $sentimentScore): static
    {
        $this->sentimentScore = $sentimentScore;
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

    public function getLastUpdated(): ?\DateTime
    {
        return $this->lastUpdated;
    }

    public function setLastUpdated(?\DateTime $lastUpdated): static
    {
        $this->lastUpdated = $lastUpdated;
        return $this;
    }

    public function calculateValues(): void
    {
        if ($this->lot && $this->costPrice) {
            $currentPrice = $this->currentPrice ?? $this->costPrice;

            $this->totalValue = $this->lot * $currentPrice;
            $this->profitLoss = $this->totalValue - ($this->lot * $this->costPrice);
            $this->profitLossPercent = $this->costPrice > 0 ? ($this->profitLoss / ($this->lot * $this->costPrice)) * 100 : 0;

            if ($this->currentPrice) {
                $this->dailyChange = $this->currentPrice - $this->costPrice;
                $this->dailyChangePercent = $this->costPrice > 0 ? ($this->dailyChange / $this->costPrice) * 100 : 0;
            }

            $this->lastUpdated = new \DateTime();
        }
    }
}
