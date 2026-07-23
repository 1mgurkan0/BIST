<?php

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class YahooHistoryService
{
    private const HISTORY_URL = 'https://query1.finance.yahoo.com/v8/finance/chart/%s?interval=1d&range=1y&events=history';
    private const BATCH_HISTORY_URL = 'https://query1.finance.yahoo.com/v7/finance/spark?symbols=%s&range=1y&interval=1d';
    private const MAX_BATCH_SIZE = 50;
    private const CACHE_TTL = 21600;
    private const LAST_SUCCESS_TTL = 604800;
    private const GLOBAL_BLOCK_KEY = 'yahoo.block.global';
    private const GLOBAL_BLOCK_DURATION = 180;

    public function __construct(
        private readonly HttpClientInterface $client,
        private readonly CacheInterface $cache,
        private readonly LockFactory $lockFactory,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @param string[] $symbols
     * @return array<string, array<string, mixed>>
     */
    public function fetchBatch(array $symbols): array
    {
        $normalized = [];
        foreach ($symbols as $symbol) {
            $symbol = $this->normalizeSymbol((string) $symbol);
            $normalized[$symbol] = true;
        }

        $symbols = array_keys($normalized);
        $results = [];
        $missing = [];

        foreach ($symbols as $symbol) {
            $cached = $this->readCache($this->cacheKey($symbol));
            if ($cached !== null) {
                $results[$symbol] = $this->result($symbol, $cached, 'ok', 'cache');
            } else {
                $missing[] = $symbol;
            }
        }

        if ($missing === []) {
            return $results;
        }

        if ($this->isGloballyBlocked()) {
            foreach ($missing as $symbol) {
                $results[$symbol] = $this->fallback($symbol, 'rate_limited', 429);
            }

            return $results;
        }

        $lock = $this->lockFactory->createLock('yahoo_history_batch', 60.0, false);
        if (!$lock->acquire()) {
            foreach ($missing as $symbol) {
                $results[$symbol] = $this->fallback($symbol, 'locked', null);
            }

            return $results;
        }

        try {
            foreach (array_chunk($missing, self::MAX_BATCH_SIZE) as $chunkIndex => $chunk) {
                if ($chunkIndex > 0) {
                    usleep(750_000);
                }

                $urlSymbols = array_map(static fn(string $symbol): string => $symbol . '.IS', $chunk);
                $response = $this->client->request('GET', sprintf(
                    self::BATCH_HISTORY_URL,
                    rawurlencode(implode(',', $urlSymbols))
                ), [
                    'headers' => [
                        'User-Agent' => 'Mozilla/5.0',
                        'Accept' => 'application/json',
                    ],
                    'timeout' => 30,
                ]);

                $httpStatus = $response->getStatusCode();
                if ($httpStatus === 429) {
                    $this->blockGlobally();
                    foreach ($chunk as $symbol) {
                        $results[$symbol] = $this->fallback($symbol, 'rate_limited', 429);
                    }
                    continue;
                }

                if ($httpStatus !== 200) {
                    foreach ($chunk as $symbol) {
                        $results[$symbol] = $this->fallback($symbol, 'http_error', $httpStatus);
                    }
                    continue;
                }

                $payload = $response->toArray(false);
                $entries = is_array($payload['spark']['result'] ?? null) ? $payload['spark']['result'] : [];
                foreach ($entries as $entry) {
                    if (!is_array($entry) || !is_string($entry['symbol'] ?? null)) {
                        continue;
                    }

                    try {
                        $symbol = $this->normalizeSymbol($entry['symbol']);
                    } catch (\InvalidArgumentException) {
                        continue;
                    }

                    if (!in_array($symbol, $chunk, true)) {
                        continue;
                    }

                    $chart = $entry['response'][0] ?? null;
                    $bars = is_array($chart) ? $this->extractBars($chart) : [];
                    if (count($bars) < 20) {
                        $results[$symbol] = $this->fallback($symbol, 'insufficient_history', null);
                        continue;
                    }

                    $data = [
                        'fetchedAt' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
                        'bars' => $bars,
                    ];
                    $this->writeCache($this->cacheKey($symbol), $data, self::CACHE_TTL);
                    $this->writeCache($this->lastSuccessKey($symbol), $data, self::LAST_SUCCESS_TTL);
                    $results[$symbol] = $this->result($symbol, $data, 'ok', 'api_batch');
                }

                foreach ($chunk as $symbol) {
                    $results[$symbol] ??= $this->fallback($symbol, 'empty_response', null);
                }
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Yahoo batch history request failed.', ['error' => $e->getMessage()]);
            foreach ($missing as $symbol) {
                $results[$symbol] ??= $this->fallback($symbol, 'request_error', null);
            }
        } finally {
            $lock->release();
        }

        return $results;
    }

    /**
     * @return array{
     *   symbol: string,
     *   status: string,
     *   source: string,
     *   httpStatus: int|null,
     *   isStale: bool,
     *   fetchedAt: string|null,
     *   bars: array<int, array{date: string, open: float|null, high: float|null, low: float|null, close: float, volume: int|null}>
     * }
     */
    public function fetch(string $symbol): array
    {
        $symbol = $this->normalizeSymbol($symbol);
        $cached = $this->readCache($this->cacheKey($symbol));
        if ($cached !== null) {
            return $this->result($symbol, $cached, 'ok', 'cache');
        }

        if ($this->isGloballyBlocked()) {
            return $this->fallback($symbol, 'rate_limited', 429);
        }

        $lock = $this->lockFactory->createLock('yahoo_history_' . strtolower($symbol), 30.0, false);
        if (!$lock->acquire()) {
            return $this->fallback($symbol, 'locked', null);
        }

        try {
            $response = $this->client->request('GET', sprintf(self::HISTORY_URL, rawurlencode($symbol . '.IS')), [
                'headers' => [
                    'Accept' => 'application/json, text/plain, */*',
                    'Accept-Language' => 'tr-TR,tr;q=0.9,en;q=0.8',
                    'Referer' => 'https://finance.yahoo.com/',
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/131 Safari/537.36',
                ],
                'timeout' => 15,
            ]);

            $httpStatus = $response->getStatusCode();
            if ($httpStatus === 429) {
                $this->blockGlobally();
                return $this->fallback($symbol, 'rate_limited', 429);
            }
            if ($httpStatus !== 200) {
                return $this->fallback($symbol, 'http_error', $httpStatus);
            }

            $payload = $response->toArray(false);
            $chart = $payload['chart']['result'][0] ?? null;
            if (!is_array($chart)) {
                return $this->fallback($symbol, 'empty_response', null);
            }

            $bars = $this->extractBars($chart);
            if (count($bars) < 20) {
                return $this->fallback($symbol, 'insufficient_history', null);
            }

            $data = [
                'fetchedAt' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
                'bars' => $bars,
            ];
            $this->writeCache($this->cacheKey($symbol), $data, self::CACHE_TTL);
            $this->writeCache($this->lastSuccessKey($symbol), $data, self::LAST_SUCCESS_TTL);

            return $this->result($symbol, $data, 'ok', 'api');
        } catch (\Throwable $e) {
            $this->logger->warning('Yahoo history request failed.', [
                'symbol' => $symbol,
                'error' => $e->getMessage(),
            ]);

            return $this->fallback($symbol, 'request_error', null);
        } finally {
            $lock->release();
        }
    }

    /**
     * @param array<string, mixed> $chart
     * @return array<int, array{date: string, open: float|null, high: float|null, low: float|null, close: float, volume: int|null}>
     */
    private function extractBars(array $chart): array
    {
        $timestamps = is_array($chart['timestamp'] ?? null) ? $chart['timestamp'] : [];
        $quote = is_array($chart['indicators']['quote'][0] ?? null) ? $chart['indicators']['quote'][0] : [];
        $timezoneName = (string) ($chart['meta']['exchangeTimezoneName'] ?? 'Europe/Istanbul');

        try {
            $timezone = new \DateTimeZone($timezoneName);
        } catch (\Throwable) {
            $timezone = new \DateTimeZone('Europe/Istanbul');
        }

        $bars = [];
        foreach ($timestamps as $index => $timestamp) {
            $close = $quote['close'][$index] ?? null;
            if (!is_numeric($timestamp) || !is_numeric($close) || (float) $close <= 0) {
                continue;
            }

            $bars[] = [
                'date' => (new \DateTimeImmutable('@' . (int) $timestamp))->setTimezone($timezone)->format('Y-m-d'),
                'open' => $this->nullableFloat($quote['open'][$index] ?? null),
                'high' => $this->nullableFloat($quote['high'][$index] ?? null),
                'low' => $this->nullableFloat($quote['low'][$index] ?? null),
                'close' => (float) $close,
                'volume' => is_numeric($quote['volume'][$index] ?? null) ? (int) $quote['volume'][$index] : null,
            ];
        }

        return $bars;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function result(string $symbol, array $data, string $status, string $source, ?int $httpStatus = null, bool $isStale = false): array
    {
        return [
            'symbol' => $symbol,
            'status' => $status,
            'source' => $source,
            'httpStatus' => $httpStatus,
            'isStale' => $isStale,
            'fetchedAt' => is_string($data['fetchedAt'] ?? null) ? $data['fetchedAt'] : null,
            'bars' => is_array($data['bars'] ?? null) ? $data['bars'] : [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function fallback(string $symbol, string $status, ?int $httpStatus): array
    {
        $lastSuccess = $this->readCache($this->lastSuccessKey($symbol));
        if ($lastSuccess !== null) {
            return $this->result($symbol, $lastSuccess, $status, 'last_success', $httpStatus, true);
        }

        return $this->result($symbol, ['bars' => [], 'fetchedAt' => null], $status, 'none', $httpStatus, true);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readCache(string $key): ?array
    {
        try {
            $item = $this->cache->getItem($key);
            $value = $item->isHit() ? $item->get() : null;

            return is_array($value) ? $value : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param array<string, mixed> $value
     */
    private function writeCache(string $key, array $value, int $ttl): void
    {
        try {
            $item = $this->cache->getItem($key);
            $item->set($value);
            $item->expiresAfter($ttl);
            $this->cache->save($item);
        } catch (\Throwable $e) {
            $this->logger->warning('Yahoo history cache write failed.', ['error' => $e->getMessage()]);
        }
    }

    private function isGloballyBlocked(): bool
    {
        try {
            return $this->cache->getItem(self::GLOBAL_BLOCK_KEY)->isHit();
        } catch (\Throwable) {
            return false;
        }
    }

    private function blockGlobally(): void
    {
        try {
            $item = $this->cache->getItem(self::GLOBAL_BLOCK_KEY);
            $item->set(true)->expiresAfter(self::GLOBAL_BLOCK_DURATION);
            $this->cache->save($item);
        } catch (\Throwable) {
        }
    }

    private function normalizeSymbol(string $symbol): string
    {
        $symbol = strtoupper(trim($symbol));
        $symbol = str_ends_with($symbol, '.IS') ? substr($symbol, 0, -3) : $symbol;

        if (!preg_match('/^[A-Z0-9]{2,20}$/', $symbol)) {
            throw new \InvalidArgumentException('Gecersiz BIST sembolu.');
        }

        return $symbol;
    }

    private function nullableFloat(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    private function cacheKey(string $symbol): string
    {
        return 'yahoo.history.1y.' . strtolower($symbol);
    }

    private function lastSuccessKey(string $symbol): string
    {
        return 'yahoo.history.last_success.' . strtolower($symbol);
    }
}
