<?php

namespace App\Entity;

use App\Repository\PriceAlertRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PriceAlertRepository::class)]
#[ORM\Table(name: 'price_alert')]
#[ORM\Index(name: 'IDX_PRICE_ALERT_SYMBOL', columns: ['symbol'])]
#[ORM\Index(name: 'IDX_PRICE_ALERT_ACTIVE_SYMBOL', columns: ['is_active', 'symbol'])]
#[ORM\HasLifecycleCallbacks]
class PriceAlert
{
    public const TYPE_PRICE_ABOVE = 'price_above';
    public const TYPE_PRICE_BELOW = 'price_below';
    public const TYPE_PERCENT_UP = 'percent_up';
    public const TYPE_PERCENT_DOWN = 'percent_down';

    public const TYPES = [
        self::TYPE_PRICE_ABOVE,
        self::TYPE_PRICE_BELOW,
        self::TYPE_PERCENT_UP,
        self::TYPE_PERCENT_DOWN,
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 20)]
    private string $symbol;

    #[ORM\Column(length: 30)]
    private string $conditionType = self::TYPE_PRICE_ABOVE;

    #[ORM\Column]
    private float $targetValue = 0.0;

    #[ORM\Column(options: ['default' => true])]
    private bool $isActive = true;

    #[ORM\Column(options: ['default' => false])]
    private bool $isTriggered = false;

    #[ORM\Column(nullable: true)]
    private ?float $lastPrice = null;

    #[ORM\Column(length: 40, nullable: true)]
    private ?string $lastQuoteStatus = null;

    #[ORM\Column(nullable: true)]
    private ?int $lastQuoteHttpStatus = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $note = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastCheckedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $triggeredAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    public function getId(): ?int
    {
        return $this->id;
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

    public function getConditionType(): string
    {
        return $this->conditionType;
    }

    public function setConditionType(string $conditionType): static
    {
        if (!in_array($conditionType, self::TYPES, true)) {
            throw new \InvalidArgumentException('Unsupported price alert condition type.');
        }

        $this->conditionType = $conditionType;
        return $this;
    }

    public function getTargetValue(): float
    {
        return $this->targetValue;
    }

    public function setTargetValue(float $targetValue): static
    {
        $this->targetValue = abs($targetValue);
        return $this;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): static
    {
        $this->isActive = $isActive;
        return $this;
    }

    public function isTriggered(): bool
    {
        return $this->isTriggered;
    }

    public function setIsTriggered(bool $isTriggered): static
    {
        $this->isTriggered = $isTriggered;
        return $this;
    }

    public function getLastPrice(): ?float
    {
        return $this->lastPrice;
    }

    public function getLastQuoteStatus(): ?string
    {
        return $this->lastQuoteStatus;
    }

    public function getLastQuoteHttpStatus(): ?int
    {
        return $this->lastQuoteHttpStatus;
    }

    public function getNote(): ?string
    {
        return $this->note;
    }

    public function setNote(?string $note): static
    {
        $note = $note === null ? null : trim($note);
        $this->note = $note === '' ? null : $note;
        return $this;
    }

    public function getLastCheckedAt(): ?\DateTimeImmutable
    {
        return $this->lastCheckedAt;
    }

    public function getTriggeredAt(): ?\DateTimeImmutable
    {
        return $this->triggeredAt;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function markChecked(?float $price, ?string $quoteStatus, ?int $httpStatus): static
    {
        $this->lastPrice = $price;
        $this->lastQuoteStatus = $quoteStatus;
        $this->lastQuoteHttpStatus = $httpStatus;
        $this->lastCheckedAt = new \DateTimeImmutable();

        return $this;
    }

    public function markTriggered(float $price, ?string $quoteStatus, ?int $httpStatus): static
    {
        $this->markChecked($price, $quoteStatus, $httpStatus);
        $this->isTriggered = true;
        $this->isActive = false;
        $this->triggeredAt = new \DateTimeImmutable();

        return $this;
    }

    public function resetTrigger(): static
    {
        $this->isTriggered = false;
        $this->isActive = true;
        $this->triggeredAt = null;

        return $this;
    }

    public function conditionLabel(): string
    {
        return match ($this->conditionType) {
            self::TYPE_PRICE_ABOVE => 'Fiyat üstüne çıkarsa',
            self::TYPE_PRICE_BELOW => 'Fiyat altına düşerse',
            self::TYPE_PERCENT_UP => 'Günlük yüzde yükselirse',
            self::TYPE_PERCENT_DOWN => 'Günlük yüzde düşerse',
            default => 'Koşul',
        };
    }

    public function targetLabel(): string
    {
        if (in_array($this->conditionType, [self::TYPE_PERCENT_UP, self::TYPE_PERCENT_DOWN], true)) {
            return '%' . number_format($this->targetValue, 2, ',', '.');
        }

        return '₺ ' . number_format($this->targetValue, 2, ',', '.');
    }

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
