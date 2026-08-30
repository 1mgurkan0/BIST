<?php

namespace App\Entity;

use App\Repository\AiSymbolHistoryRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: AiSymbolHistoryRepository::class)]
#[ORM\Table(name: 'ai_symbol_history')]
#[ORM\UniqueConstraint(name: 'uniq_symbol_date', columns: ['symbol', 'record_date'])]
class AiSymbolHistory
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 20)]
    private ?string $symbol = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private ?\DateTimeInterface $recordDate = null;

    #[ORM\Column(length: 20)]
    private ?string $decision = null;

    #[ORM\Column(length: 20)]
    private ?string $trend = null;

    #[ORM\Column]
    private ?float $price = null;

    #[ORM\Column]
    private ?float $rsi = null;

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
        $this->symbol = $symbol;

        return $this;
    }

    public function getRecordDate(): ?\DateTimeInterface
    {
        return $this->recordDate;
    }

    public function setRecordDate(\DateTimeInterface $recordDate): static
    {
        $this->recordDate = $recordDate;

        return $this;
    }

    public function getDecision(): ?string
    {
        return $this->decision;
    }

    public function setDecision(string $decision): static
    {
        $this->decision = $decision;

        return $this;
    }

    public function getTrend(): ?string
    {
        return $this->trend;
    }

    public function setTrend(string $trend): static
    {
        $this->trend = $trend;

        return $this;
    }

    public function getPrice(): ?float
    {
        return $this->price;
    }

    public function setPrice(float $price): static
    {
        $this->price = $price;

        return $this;
    }

    public function getRsi(): ?float
    {
        return $this->rsi;
    }

    public function setRsi(float $rsi): static
    {
        $this->rsi = $rsi;

        return $this;
    }
}