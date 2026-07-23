<?php

namespace App\Service;

use App\DTO\MarketDataDto;
use App\Entity\Portfolio;
use App\Entity\PriceAlert;
use App\Entity\WatchlistItem;
use App\Repository\PortfolioRepository;
use App\Repository\PriceAlertRepository;
use App\Repository\WatchlistItemRepository;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;

class PriceSnapshotService
{
    public const CACHE_KEY = 'tracked_prices.snapshot';
    public const CACHE_TTL = 86400;
    public const MAX_FRESH_AGE_SECONDS = 900;

    public function __construct(
        private readonly CacheInterface $cache,
        private readonly YahooFinanceService $yahooFinance,
        private readonly PortfolioRepository $portfolioRepository,
        private readonly WatchlistItemRepository $watchlistRepository,
        private readonly PriceAlertRepository $alertRepository,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @return string[]
     */
    public function trackedSymbols(): array
    {
        $symbols = [];

        foreach ($this->portfolioRepository->findAll() as $portfolio) {
            if ($portfolio instanceof Portfolio) {
                $symbols[] = (string) $portfolio->getSymbol();
            }
        }

        foreach ($this->watchlistRepository->findActive() as $item) {
            if ($item instanceof WatchlistItem) {
                $symbols[] = $item->getSymbol();
            }
        }

        foreach ($this->alertRepository->findActive() as $alert) {
            if ($alert instanceof PriceAlert) {
                $symbols[] = $alert->getSymbol();
            }
        }

        return $this->normalizeSymbols($symbols);
    }

    /**
     * @param string[]|null $symbols
     * @return array<string, mixed>
     */
    public function refresh(?array $symbols = null, bool $dryRun = false): array
    {
        $partialRefresh = $symbols !== null;
        $symbols = $symbols === null ? $this->trackedSymbols() : $this->normalizeSymbols($symbols);
        $startedAt = new \DateTimeImmutable();

        if (empty($symbols)) {
            $payload = $this->emptySnapshot('no_symbols', 'Portfolio, takip listesi veya aktif alarm sembolu yok.');

            if (!$dryRun && !$partialRefresh) {
                $this->saveSnapshot($payload);
            }

            return $payload;
        }

        $statusMap = $this->yahooFinance->fetchBatchWithStatus($symbols);
        $items = [];
        $fresh = 0;
        $stale = 0;
        $missing = 0;
        $rateLimited = 0;

        foreach ($symbols as $symbol) {
            $item = $this->buildItem($symbol, $statusMap[$symbol] ?? null);
            $items[$symbol] = $item;

            if ($item['price'] === null) {
                $missing++;
            } elseif ($item['quoteStatus'] === 'ok' && !$item['isStale']) {
                $fresh++;
            } else {
                $stale++;
            }

            if ((int) ($item['httpStatus'] ?? 0) === 429) {
                $rateLimited++;
            }
        }

        $snapshotStatus = 'ok';
        $message = 'Tum semboller taze veriyle guncellendi.';

        if ($missing === count($symbols)) {
            $snapshotStatus = 'failed';
            $message = 'Yahoo fiyat verisi vermedi ve korunacak son basarili fiyat bulunamadi.';
        } elseif ($fresh === 0) {
            $snapshotStatus = 'stale_only';
            $message = 'Yahoo yeni veri vermedi. Son basarili fiyatlar korunuyor.';
        } elseif ($missing > 0 || $stale > 0) {
            $snapshotStatus = 'partial';
            $message = 'Kismi guncelleme yapildi. Bazi semboller son basarili fiyatla korunuyor.';
        }

        $payload = [
            'status' => $snapshotStatus,
            'message' => $message,
            'fetchedAt' => $startedAt->format(\DateTimeInterface::ATOM),
            'updatedAt' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'symbols' => $symbols,
            'summary' => [
                'total' => count($symbols),
                'fresh' => $fresh,
                'stale' => $stale,
                'missing' => $missing,
                'rateLimited' => $rateLimited,
            ],
            'items' => $items,
        ];

        if (!$dryRun) {
            $this->saveSnapshot($partialRefresh ? $this->mergeWithCurrentSnapshot($payload) : $payload);
        }

        $this->logger->info('Tracked price snapshot refreshed.', [
            'total' => count($symbols),
            'fresh' => $fresh,
            'stale' => $stale,
            'missing' => $missing,
            'rate_limited' => $rateLimited,
            'dry_run' => $dryRun,
        ]);

        return $payload;
    }

    /**
     * A manual/report-specific refresh must not erase unrelated tracked symbols.
     *
     * @param array<string, mixed> $partial
     * @return array<string, mixed>
     */
    private function mergeWithCurrentSnapshot(array $partial): array
    {
        $current = $this->snapshot();
        $currentItems = is_array($current['items'] ?? null) ? $current['items'] : [];
        $partialItems = is_array($partial['items'] ?? null) ? $partial['items'] : [];

        foreach ($currentItems as $symbol => $item) {
            if (is_array($item)) {
                $currentItems[$symbol] = $this->markAgedItemStale($item);
            }
        }

        $items = array_replace($currentItems, $partialItems);
        ksort($items);
        $summary = $this->summarizeItems($items);

        return [
            'status' => $this->snapshotStatus($summary),
            'message' => 'Kismi yenileme mevcut fiyat snapshotina birlestirildi.',
            'fetchedAt' => $partial['fetchedAt'] ?? null,
            'updatedAt' => $partial['updatedAt'] ?? null,
            'symbols' => array_keys($items),
            'summary' => $summary,
            'items' => $items,
        ];
    }

    /**
     * @param array<string, array<string, mixed>> $items
     * @return array{total: int, fresh: int, stale: int, missing: int, rateLimited: int}
     */
    private function summarizeItems(array $items): array
    {
        $summary = ['total' => count($items), 'fresh' => 0, 'stale' => 0, 'missing' => 0, 'rateLimited' => 0];

        foreach ($items as $item) {
            if (!is_numeric($item['price'] ?? null)) {
                ++$summary['missing'];
            } elseif (($item['quoteStatus'] ?? null) === 'ok' && empty($item['isStale'])) {
                ++$summary['fresh'];
            } else {
                ++$summary['stale'];
            }

            if ((int) ($item['httpStatus'] ?? 0) === 429) {
                ++$summary['rateLimited'];
            }
        }

        return $summary;
    }

    /** @param array{total: int, fresh: int, stale: int, missing: int, rateLimited: int} $summary */
    private function snapshotStatus(array $summary): string
    {
        if ($summary['total'] === 0) {
            return 'no_symbols';
        }
        if ($summary['missing'] === $summary['total']) {
            return 'failed';
        }
        if ($summary['fresh'] === 0) {
            return 'stale_only';
        }
        if ($summary['missing'] > 0 || $summary['stale'] > 0) {
            return 'partial';
        }

        return 'ok';
    }

    /**
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        try {
            $item = $this->cache->getItem(self::CACHE_KEY);
            if ($item->isHit()) {
                $payload = $item->get();

                if (is_array($payload)) {
                    return $payload;
                }
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Tracked price snapshot read failed.', ['error' => $e->getMessage()]);
        }

        return $this->emptySnapshot('waiting_for_refresh', 'Fiyat snapshot komutu henuz calismadi.');
    }

    /**
     * @param string[] $symbols
     * @return array<string, mixed>
     */
    public function payloadForSymbols(array $symbols): array
    {
        $symbols = $this->normalizeSymbols($symbols);
        $snapshot = $this->snapshot();
        $snapshotItems = is_array($snapshot['items'] ?? null) ? $snapshot['items'] : [];
        $items = [];

        foreach ($symbols as $symbol) {
            $items[$symbol] = is_array($snapshotItems[$symbol] ?? null)
                ? $this->markAgedItemStale($snapshotItems[$symbol])
                : $this->emptyItem($symbol, $snapshot['status'] === 'waiting_for_refresh' ? 'waiting_for_refresh' : 'missing_snapshot');
        }

        return [
            'status' => $snapshot['status'] ?? 'unknown',
            'message' => $snapshot['message'] ?? null,
            'fetchedAt' => $snapshot['fetchedAt'] ?? null,
            'updatedAt' => $snapshot['updatedAt'] ?? null,
            'summary' => $snapshot['summary'] ?? null,
            'items' => $items,
        ];
    }

    /**
     * @param string[] $symbols
     * @return array<string, array<string, mixed>>
     */
    public function itemsForSymbols(array $symbols): array
    {
        /** @var array<string, array<string, mixed>> $items */
        $items = $this->payloadForSymbols($symbols)['items'];

        return $items;
    }

    /**
     * @param string[] $symbols
     * @return string[]
     */
    private function normalizeSymbols(array $symbols): array
    {
        $normalized = [];

        foreach ($symbols as $symbol) {
            $symbol = $this->normalizeSymbol((string) $symbol);

            if ($symbol !== null) {
                $normalized[$symbol] = true;
            }
        }

        return array_keys($normalized);
    }

    private function normalizeSymbol(string $symbol): ?string
    {
        $symbol = strtoupper(trim($symbol));

        if (str_ends_with($symbol, '.IS')) {
            $symbol = substr($symbol, 0, -3);
        }

        return preg_match('/^[A-Z0-9]{2,20}$/', $symbol) ? $symbol : null;
    }

    /**
     * @param array<string, mixed>|null $quoteStatus
     * @return array<string, mixed>
     */
    private function buildItem(string $symbol, ?array $quoteStatus): array
    {
        $data = $quoteStatus['data'] ?? null;
        $lastSuccessful = $quoteStatus['lastSuccessful'] ?? null;
        $status = (string) ($quoteStatus['status'] ?? 'missing_price');

        if (!$data instanceof MarketDataDto) {
            return $this->emptyItem($symbol, $status, $quoteStatus, $lastSuccessful);
        }

        $previousClose = $data->previousClose;
        $dailyChange = $data->price - $previousClose;

        return [
            'symbol' => $symbol,
            'price' => $data->price,
            'open' => $data->open,
            'high' => $data->high,
            'low' => $data->low,
            'previousClose' => $previousClose,
            'volume' => $data->volume,
            'dailyChange' => $dailyChange,
            'dailyChangePercent' => $data->changePercent(),
            'status' => $status,
            'quoteStatus' => $status,
            'source' => $quoteStatus['source'] ?? null,
            'httpStatus' => $quoteStatus['httpStatus'] ?? null,
            'statusMessage' => $quoteStatus['message'] ?? null,
            'isStale' => (bool) ($quoteStatus['isStale'] ?? false),
            'fetchedAt' => $data->fetchedAt->format(\DateTimeInterface::ATOM),
            'lastSuccessfulPrice' => $lastSuccessful instanceof MarketDataDto ? $lastSuccessful->price : null,
            'lastSuccessfulAt' => $lastSuccessful instanceof MarketDataDto ? $lastSuccessful->fetchedAt->format(\DateTimeInterface::ATOM) : null,
        ];
    }

    /**
     * @param array<string, mixed>|null $quoteStatus
     * @return array<string, mixed>
     */
    private function emptyItem(
        string $symbol,
        string $status,
        ?array $quoteStatus = null,
        mixed $lastSuccessful = null,
    ): array {
        return [
            'symbol' => $symbol,
            'price' => null,
            'open' => null,
            'high' => null,
            'low' => null,
            'previousClose' => null,
            'volume' => null,
            'dailyChange' => null,
            'dailyChangePercent' => null,
            'status' => $status,
            'quoteStatus' => $status,
            'source' => $quoteStatus['source'] ?? null,
            'httpStatus' => $quoteStatus['httpStatus'] ?? null,
            'statusMessage' => $quoteStatus['message'] ?? null,
            'isStale' => (bool) ($quoteStatus['isStale'] ?? false),
            'fetchedAt' => null,
            'lastSuccessfulPrice' => $lastSuccessful instanceof MarketDataDto ? $lastSuccessful->price : null,
            'lastSuccessfulAt' => $lastSuccessful instanceof MarketDataDto ? $lastSuccessful->fetchedAt->format(\DateTimeInterface::ATOM) : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function emptySnapshot(string $status, string $message): array
    {
        return [
            'status' => $status,
            'message' => $message,
            'fetchedAt' => null,
            'updatedAt' => null,
            'symbols' => [],
            'summary' => [
                'total' => 0,
                'fresh' => 0,
                'stale' => 0,
                'missing' => 0,
                'rateLimited' => 0,
            ],
            'items' => [],
        ];
    }

    /**
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    private function markAgedItemStale(array $item): array
    {
        if (!empty($item['isStale']) || !is_numeric($item['price'] ?? null)) {
            return $item;
        }

        $fetchedAt = $item['fetchedAt'] ?? null;
        if (!is_string($fetchedAt) || $fetchedAt === '') {
            $item['isStale'] = true;
            $item['quoteStatus'] = 'stale_snapshot';
            $item['status'] = 'stale_snapshot';
            $item['statusMessage'] = 'Fiyat snapshot zamani bilinmiyor.';
            return $item;
        }

        try {
            $age = time() - (new \DateTimeImmutable($fetchedAt))->getTimestamp();
        } catch (\Throwable) {
            $age = self::MAX_FRESH_AGE_SECONDS + 1;
        }

        if ($age > self::MAX_FRESH_AGE_SECONDS) {
            $item['isStale'] = true;
            $item['quoteStatus'] = 'stale_snapshot';
            $item['status'] = 'stale_snapshot';
            $item['statusMessage'] = sprintf('Fiyat snapshoti %d dakikadan eski.', (int) floor($age / 60));
        }

        return $item;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function saveSnapshot(array $payload): void
    {
        try {
            $item = $this->cache->getItem(self::CACHE_KEY);
            $item->set($payload);
            $item->expiresAfter(self::CACHE_TTL);
            $this->cache->save($item);
        } catch (\Throwable $e) {
            $this->logger->error('Tracked price snapshot write failed.', ['error' => $e->getMessage()]);
        }
    }
}
