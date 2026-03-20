<?php

namespace App\DTO;

final class MarketDataDto
{
    public function __construct(
        public readonly string             $symbol,
        public readonly float              $price,
        public readonly float              $open,
        public readonly float              $high,
        public readonly float              $low,
        public readonly float              $previousClose,
        public readonly int                $volume,
        public readonly \DateTimeImmutable $fetchedAt,
    ) {}

    public static function fromYahooQuote(array $raw): self
    {
        return new self(
            symbol:        strtoupper(str_replace('.IS', '', $raw['symbol'] ?? '')),
            price:         (float) ($raw['regularMarketPrice']         ?? 0.0),
            open:          (float) ($raw['regularMarketOpen']          ?? 0.0),
            high:          (float) ($raw['regularMarketDayHigh']       ?? 0.0),
            low:           (float) ($raw['regularMarketDayLow']        ?? 0.0),
            previousClose: (float) ($raw['regularMarketPreviousClose'] ?? 0.0),
            volume:        (int)   ($raw['regularMarketVolume']        ?? 0),
            fetchedAt:     new \DateTimeImmutable(),
        );
    }

    public function isValid(): bool
    {
        return $this->price > 0.0;
    }

    public function changePercent(): float
    {
        if ($this->previousClose <= 0.0) {
            return 0.0;
        }
        return round((($this->price - $this->previousClose) / $this->previousClose) * 100, 2);
    }
}