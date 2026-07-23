<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'stock')]
#[ORM\Index(name: 'IDX_STOCK_SYMBOL_CREATED', columns: ['symbol', 'created_at'])]
class Stock
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 20)]
    private string $symbol;

    #[ORM\Column(type: 'float')]
    private float $price;

    #[ORM\Column(type: 'float')]
    private float $open;

    #[ORM\Column(type: 'float')]
    private float $high;

    #[ORM\Column(type: 'float')]
    private float $low;

    #[ORM\Column(type: 'float')]
    private float $previousClose;

    #[ORM\Column(type: 'bigint')]
    private int $volume;

    #[ORM\Column(type: 'datetime')]
    private \DateTimeInterface $createdAt;

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

    public function getPrice(): ?float
    {
        return $this->price;
    }

    public function setPrice(float $price): static
    {
        $this->price = $price;

        return $this;
    }

    public function getOpen(): ?float
    {
        return $this->open;
    }

    public function setOpen(float $open): static
    {
        $this->open = $open;

        return $this;
    }

    public function getHigh(): ?float
    {
        return $this->high;
    }

    public function setHigh(float $high): static
    {
        $this->high = $high;

        return $this;
    }

    public function getLow(): ?float
    {
        return $this->low;
    }

    public function setLow(float $low): static
    {
        $this->low = $low;

        return $this;
    }

    public function getPreviousClose(): ?float
    {
        return $this->previousClose;
    }

    public function setPreviousClose(float $previousClose): static
    {
        $this->previousClose = $previousClose;

        return $this;
    }

    public function getVolume(): ?int
    {
        return $this->volume;
    }

    public function setVolume(int $volume): static
    {
        $this->volume = max(0, $volume);

        return $this;
    }

    public function getCreatedAt(): ?\DateTime
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTime $createdAt): static
    {
        $this->createdAt = $createdAt;

        return $this;
    }

}
