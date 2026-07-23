<?php

namespace App\Service;

use App\DTO\MarketDataDto;
use Psr\Log\LoggerInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class YahooFinanceService
{
    private const BASE_URL = 'https://query1.finance.yahoo.com/v8/finance/chart/%s?interval=1d&range=1d';
    private const BATCH_URL = 'https://query1.finance.yahoo.com/v7/finance/spark?symbols=%s&range=1d&interval=5m';
    private const SYMBOL_TTL = 60;
    private const LAST_SUCCESS_TTL = 604800;
    private const BLOCK_DURATION = 600;
    private const BATCH_BLOCK_DURATION = 45;
    private const BATCH_BLOCK_KEY = 'yahoo.block.quote_batch';
    private const MAX_BATCH_SIZE = 50;
    private const REQUEST_DELAY = 8_000_000;
    private const MAX_RETRY = 3;
    private const BATCH_CURSOR_KEY = 'yahoo.batch.cursor';

    private const USER_AGENTS = [
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36',
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36',
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36',
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:133.0) Gecko/20100101 Firefox/133.0',
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_7_1) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.1 Safari/605.1.15',
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36 Edg/131.0.0.0',
    ];

    private const ACCEPT_LANGUAGES = [
        'en-US,en;q=0.9',
        'en-GB,en;q=0.9,en-US;q=0.8',
        'tr-TR,tr;q=0.9,en-US;q=0.8,en;q=0.7',
    ];

    private const REFERERS = [
        'https://finance.yahoo.com/',
        'https://finance.yahoo.com/markets/',
        'https://finance.yahoo.com/quote/THYAO.IS/',
        'https://www.google.com/',
    ];

    public function __construct(
        private readonly HttpClientInterface $client,
        private readonly CacheInterface $cache,
        private readonly LockFactory $lockFactory,
        private readonly LoggerInterface $logger,
    ) {}

    public function fetchOne(string $symbol): ?MarketDataDto
    {
        $result = $this->fetchOneWithStatus($symbol);

        return $result['data'] instanceof MarketDataDto ? $result['data'] : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function fetchOneWithStatus(string $symbol): array
    {
        $normalizedSymbol = $this->normalizeSymbol($symbol);

        $cached = $this->getFromCache($normalizedSymbol);
        if ($cached !== null) {
            return $this->buildStatus($normalizedSymbol, $cached, 'cache', 'ok');
        }

        $bareSymbol = $this->bareSymbol($normalizedSymbol);
        $results = $this->fetchBatchWithStatus([$bareSymbol]);

        return $results[$bareSymbol] ?? $this->buildStatusFromLast(
            $normalizedSymbol,
            'missing_price',
            null,
            'Yahoo sembol icin fiyat dondurmedi. Son basarili veri gosteriliyor.'
        );
    }

    /**
     * @param string[] $symbols
     * @return array<string, MarketDataDto>
     */
    public function fetchBatch(array $symbols): array
    {
        $statusMap = $this->fetchBatchWithStatus($symbols);
        $results = [];

        foreach ($statusMap as $symbol => $result) {
            if (($result['data'] ?? null) instanceof MarketDataDto) {
                $results[$symbol] = $result['data'];
            }
        }

        return $results;
    }

    /**
     * @param string[] $symbols
     * @return array<string, array<string, mixed>>
     */
    public function fetchBatchWithStatus(array $symbols): array
    {
        if (empty($symbols)) {
            return [];
        }

        $results = [];
        $missing = [];
        $seen = [];

        foreach ($symbols as $raw) {
            $symbol = $this->normalizeSymbol($raw);

            if (isset($seen[$symbol])) {
                continue;
            }
            $seen[$symbol] = true;

            $cached = $this->getFromCache($symbol);
            if ($cached !== null) {
                $results[$cached->symbol] = $this->buildStatus($symbol, $cached, 'cache', 'ok');
                $this->logger->debug('Yahoo cache hit.', ['symbol' => $symbol]);
                continue;
            }

            $missing[] = $symbol;
        }

        if (empty($missing)) {
            $this->logger->info('All symbols served from Yahoo cache.', ['count' => count($results)]);
            return $results;
        }

        foreach (array_chunk($missing, self::MAX_BATCH_SIZE) as $chunk) {
            $batchResults = $this->fetchBatchFromApiWithStatus($chunk);
            foreach ($batchResults as $symbol => $status) {
                $results[$symbol] = $status;
            }
        }

        $missing = array_values(array_filter(
            $missing,
            fn(string $symbol): bool => !isset($results[$this->bareSymbol($symbol)])
        ));

        if (empty($missing)) {
            $this->logger->info('Yahoo batch quote completed.', [
                'requested' => count($seen),
                'returned' => count($results),
            ]);

            return $results;
        }

        $missing = $this->rotateBatch($missing);

        $this->logger->info('Symbols will be fetched from Yahoo.', [
            'count' => count($missing),
            'symbols' => implode(', ', $missing),
        ]);

        foreach ($missing as $index => $symbol) {
            if ($this->isBlocked($symbol)) {
                $this->logger->notice('Symbol is in Yahoo 429 block, using last successful quote.', ['symbol' => $symbol]);
                $status = $this->buildStatusFromLast(
                    $symbol,
                    'rate_limited',
                    429,
                    'Yahoo 429 limiti aktif. Son basarili veri gosteriliyor.'
                );
            } else {
                $status = $this->fetchFromApiWithStatus($symbol);
            }

            $results[$status['symbol']] = $status;

            if ($index < count($missing) - 1) {
                $jitter = random_int(0, 800_000);
                usleep(self::REQUEST_DELAY + $jitter);
            }
        }

        $this->logger->info('Yahoo fetchBatch completed.', [
            'requested' => count($seen),
            'returned' => count($results),
            'without_data' => count(array_filter(
                $results,
                fn(array $item): bool => ($item['data'] ?? null) === null
            )),
        ]);

        return $results;
    }

    /**
     * @param string[] $normalizedSymbols
     * @return array<string, array<string, mixed>>
     */
    private function fetchBatchFromApiWithStatus(array $normalizedSymbols): array
    {
        if (empty($normalizedSymbols)) {
            return [];
        }

        if ($this->isBatchBlocked()) {
            $results = [];
            foreach ($normalizedSymbols as $symbol) {
                $results[$this->bareSymbol($symbol)] = $this->buildStatusFromLast(
                    $symbol,
                    'rate_limited',
                    429,
                    'Yahoo batch limiti kisa sure once tetiklendi. Son basarili veri gosteriliyor.'
                );
            }

            return $results;
        }

        $lock = $this->lockFactory->createLock('yahoo_batch_quote', 20.0, false);
        if (!$lock->acquire()) {
            $results = [];
            foreach ($normalizedSymbols as $symbol) {
                $results[$this->bareSymbol($symbol)] = $this->buildStatusFromLast(
                    $symbol,
                    'locked',
                    null,
                    'Baska bir Yahoo batch istegi suruyor. Son basarili veri gosteriliyor.'
                );
            }

            return $results;
        }

        try {
            $encodedSymbols = rawurlencode(implode(',', $normalizedSymbols));
            $response = $this->client->request('GET', sprintf(self::BATCH_URL, $encodedSymbols), [
                'headers' => [
                    'User-Agent' => 'Mozilla/5.0',
                    'Accept' => 'application/json',
                ],
                'timeout' => 15,
            ]);
            $httpStatus = $response->getStatusCode();

            if ($httpStatus === 429) {
                $this->logger->warning('Yahoo batch quote returned 429.', [
                    'symbols' => implode(', ', $normalizedSymbols),
                ]);
                $this->blockBatch();
                $results = [];
                foreach ($normalizedSymbols as $symbol) {
                    $results[$this->bareSymbol($symbol)] = $this->buildStatusFromLast(
                        $symbol,
                        'rate_limited',
                        429,
                        'Yahoo batch istegine 429 verdi. Son basarili veri gosteriliyor.'
                    );
                }

                return $results;
            }

            if ($httpStatus !== 200) {
                $this->logger->warning('Yahoo batch quote returned unexpected status.', ['status' => $httpStatus]);
                return [];
            }

            $payload = $response->toArray(false);
            $entries = is_array($payload['spark']['result'] ?? null) ? $payload['spark']['result'] : [];
            $requested = array_fill_keys($normalizedSymbols, true);
            $results = [];

            foreach ($entries as $entry) {
                if (!is_array($entry) || !is_string($entry['symbol'] ?? null)) {
                    continue;
                }

                try {
                    $symbol = $this->normalizeSymbol($entry['symbol']);
                } catch (\InvalidArgumentException) {
                    continue;
                }

                if (!isset($requested[$symbol])) {
                    continue;
                }

                $chart = $entry['response'][0] ?? null;
                $dto = is_array($chart) ? $this->dtoFromChart($symbol, $chart) : null;
                if (!$dto instanceof MarketDataDto || !$dto->isValid()) {
                    continue;
                }

                $this->saveToCache($symbol, $dto);
                $this->clearQuoteBlocks($symbol);
                $results[$dto->symbol] = $this->buildStatus($symbol, $dto, 'api_batch', 'ok');
            }

            return $results;
        } catch (\Throwable $e) {
            $this->logger->warning('Yahoo batch quote request failed.', ['error' => $e->getMessage()]);
            return [];
        } finally {
            $lock->release();
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchFromApiWithStatus(string $normalizedSymbol): array
    {
        $lock = $this->lockFactory->createLock('yahoo_fetch_' . $normalizedSymbol, 15.0, false);

        if (!$lock->acquire()) {
            usleep(500_000);
            $cached = $this->getFromCache($normalizedSymbol);

            if ($cached !== null) {
                return $this->buildStatus($normalizedSymbol, $cached, 'cache', 'ok');
            }

            return $this->buildStatusFromLast(
                $normalizedSymbol,
                'locked',
                null,
                'Ayni sembol icin baska veri cekimi suruyor. Son basarili veri gosteriliyor.'
            );
        }

        try {
            return $this->doRequestWithStatus($normalizedSymbol);
        } finally {
            $lock->release();
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function doRequestWithStatus(string $symbol): array
    {
        $url = sprintf(self::BASE_URL, $symbol);

        for ($attempt = 0; $attempt < self::MAX_RETRY; $attempt++) {
            $ua = self::USER_AGENTS[array_rand(self::USER_AGENTS)];
            $lang = self::ACCEPT_LANGUAGES[array_rand(self::ACCEPT_LANGUAGES)];
            $referer = self::REFERERS[array_rand(self::REFERERS)];

            try {
                $response = $this->client->request('GET', $url, [
                    'headers' => [
                        'User-Agent' => $ua,
                        'Accept' => 'application/json, text/plain, */*',
                        'Accept-Language' => $lang,
                        'Accept-Encoding' => 'gzip, deflate, br',
                        'Referer' => $referer,
                        'Origin' => 'https://finance.yahoo.com',
                        'Connection' => 'keep-alive',
                        'Sec-Fetch-Dest' => 'empty',
                        'Sec-Fetch-Mode' => 'cors',
                        'Sec-Fetch-Site' => 'same-site',
                    ],
                    'timeout' => 10,
                ]);

                $status = $response->getStatusCode();

                if ($status === 429) {
                    $this->logger->warning('Yahoo returned 429, using last successful quote.', [
                        'symbol' => $symbol,
                        'attempt' => $attempt + 1,
                    ]);
                    $this->block($symbol);

                    return $this->buildStatusFromLast(
                        $symbol,
                        'rate_limited',
                        429,
                        'Yahoo 429 verdi. Son basarili veri gosteriliyor.'
                    );
                }

                if ($status !== 200) {
                    $this->logger->error('Unexpected Yahoo HTTP status.', [
                        'symbol' => $symbol,
                        'status' => $status,
                        'attempt' => $attempt + 1,
                    ]);

                    if ($status >= 500 && $attempt < self::MAX_RETRY - 1) {
                        sleep($this->backoffSeconds($attempt));
                        continue;
                    }

                    return $this->buildStatusFromLast(
                        $symbol,
                        'http_error',
                        $status,
                        'Yahoo beklenmeyen HTTP kodu dondu. Son basarili veri gosteriliyor.'
                    );
                }

                $data = $response->toArray();

                if (empty($data['chart']['result'][0])) {
                    $this->logger->notice('Yahoo returned an empty result.', ['symbol' => $symbol]);

                    return $this->buildStatusFromLast(
                        $symbol,
                        'empty_response',
                        null,
                        'Yahoo bos cevap dondu. Son basarili veri gosteriliyor.'
                    );
                }

                $result = $data['chart']['result'][0];
                $dto = $this->dtoFromChart($symbol, $result);

                if (!$dto instanceof MarketDataDto || !$dto->isValid()) {
                    $this->logger->notice('Yahoo returned a quote without a valid price.', ['symbol' => $symbol]);

                    return $this->buildStatusFromLast(
                        $symbol,
                        'invalid_quote',
                        null,
                        'Yahoo gecerli bir fiyat dondurmedi. Son basarili veri gosteriliyor.'
                    );
                }

                $this->saveToCache($symbol, $dto);

                $this->logger->info('Yahoo quote fetched.', [
                    'symbol' => $dto->symbol,
                    'price' => $dto->price,
                ]);

                return $this->buildStatus($symbol, $dto, 'api', 'ok');
            } catch (\Throwable $e) {
                $this->logger->error('Yahoo request failed.', [
                    'symbol' => $symbol,
                    'error' => $e->getMessage(),
                    'attempt' => $attempt + 1,
                ]);

                if ($attempt < self::MAX_RETRY - 1) {
                    sleep($this->backoffSeconds($attempt));
                    continue;
                }

                return $this->buildStatusFromLast(
                    $symbol,
                    'request_error',
                    null,
                    'Yahoo istegi basarisiz oldu. Son basarili veri gosteriliyor.'
                );
            }
        }

        $this->logger->error('Yahoo retry limit exceeded.', ['symbol' => $symbol]);

        return $this->buildStatusFromLast(
            $symbol,
            'retry_exceeded',
            null,
            'Yahoo retry siniri asildi. Son basarili veri gosteriliyor.'
        );
    }

    private function getFromCache(string $normalizedSymbol): ?MarketDataDto
    {
        try {
            $item = $this->cache->getItem($this->cacheKey($normalizedSymbol));
            if ($item->isHit()) {
                $dto = $item->get();

                return $dto instanceof MarketDataDto ? $dto : null;
            }
        } catch (\Throwable) {
        }

        return null;
    }

    private function getLastSuccessfulFromCache(string $normalizedSymbol): ?MarketDataDto
    {
        try {
            $item = $this->cache->getItem($this->lastSuccessCacheKey($normalizedSymbol));
            if ($item->isHit()) {
                $dto = $item->get();

                return $dto instanceof MarketDataDto ? $dto : null;
            }
        } catch (\Throwable) {
        }

        return null;
    }

    private function saveToCache(string $normalizedSymbol, MarketDataDto $dto): void
    {
        try {
            $item = $this->cache->getItem($this->cacheKey($normalizedSymbol));
            $item->set($dto);
            $item->expiresAfter(self::SYMBOL_TTL);
            $this->cache->save($item);
        } catch (\Throwable $e) {
            $this->logger->warning('Yahoo short cache write failed.', [
                'symbol' => $normalizedSymbol,
                'error' => $e->getMessage(),
            ]);
        }

        $this->saveLastSuccessfulToCache($normalizedSymbol, $dto);
    }

    private function block(string $symbol): void
    {
        try {
            $item = $this->cache->getItem($this->blockKey($symbol));
            $item->set(true);
            $item->expiresAfter(self::BLOCK_DURATION);
            $this->cache->save($item);
        } catch (\Throwable) {
        }
    }

    private function isBlocked(string $symbol): bool
    {
        try {
            return $this->cache->getItem($this->blockKey($symbol))->isHit();
        } catch (\Throwable) {
            return false;
        }
    }

    private function blockBatch(): void
    {
        try {
            $item = $this->cache->getItem(self::BATCH_BLOCK_KEY);
            $item->set(true)->expiresAfter(self::BATCH_BLOCK_DURATION);
            $this->cache->save($item);
        } catch (\Throwable) {
        }
    }

    private function isBatchBlocked(): bool
    {
        try {
            return $this->cache->getItem(self::BATCH_BLOCK_KEY)->isHit();
        } catch (\Throwable) {
            return false;
        }
    }

    private function clearQuoteBlocks(string $symbol): void
    {
        try {
            $this->cache->delete(self::BATCH_BLOCK_KEY);
            $this->cache->delete($this->blockKey($symbol));
        } catch (\Throwable) {
        }
    }

    /**
     * Rotating the first symbol prevents a rate limit from starving symbols
     * that happen to appear later in every scheduled batch.
     *
     * @param string[] $symbols
     * @return string[]
     */
    private function rotateBatch(array $symbols): array
    {
        if (count($symbols) < 2) {
            return $symbols;
        }

        try {
            $item = $this->cache->getItem(self::BATCH_CURSOR_KEY);
            $cursor = $item->isHit() && is_numeric($item->get()) ? (int) $item->get() : 0;
            $offset = $cursor % count($symbols);
            $item->set($cursor + 1)->expiresAfter(self::LAST_SUCCESS_TTL);
            $this->cache->save($item);

            return array_merge(array_slice($symbols, $offset), array_slice($symbols, 0, $offset));
        } catch (\Throwable) {
            return $symbols;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function buildStatus(
        string $normalizedSymbol,
        ?MarketDataDto $data,
        string $source,
        string $status,
        ?int $httpStatus = null,
        ?string $message = null,
        bool $isStale = false,
    ): array {
        if ($data instanceof MarketDataDto && !$isStale && $status === 'ok') {
            $this->saveLastSuccessfulToCache($normalizedSymbol, $data);
        }

        $lastSuccessful = $data ?? $this->getLastSuccessfulFromCache($normalizedSymbol);

        return [
            'symbol' => $data?->symbol ?? $this->bareSymbol($normalizedSymbol),
            'data' => $data,
            'lastSuccessful' => $lastSuccessful,
            'source' => $source,
            'status' => $status,
            'httpStatus' => $httpStatus,
            'message' => $message,
            'isStale' => $isStale,
            'fetchedAt' => $data?->fetchedAt,
            'lastSuccessfulAt' => $lastSuccessful?->fetchedAt,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildStatusFromLast(
        string $normalizedSymbol,
        string $status,
        ?int $httpStatus,
        string $message,
    ): array {
        $lastSuccessful = $this->getLastSuccessfulFromCache($normalizedSymbol);

        return $this->buildStatus(
            $normalizedSymbol,
            $lastSuccessful,
            $lastSuccessful instanceof MarketDataDto ? 'last_success' : 'none',
            $status,
            $httpStatus,
            $message,
            $lastSuccessful instanceof MarketDataDto
        );
    }

    private function saveLastSuccessfulToCache(string $normalizedSymbol, MarketDataDto $dto): void
    {
        try {
            $lastItem = $this->cache->getItem($this->lastSuccessCacheKey($normalizedSymbol));
            $lastItem->set($dto);
            $lastItem->expiresAfter(self::LAST_SUCCESS_TTL);
            $this->cache->save($lastItem);
        } catch (\Throwable $e) {
            $this->logger->warning('Yahoo last-success cache write failed.', [
                'symbol' => $normalizedSymbol,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param array<string, mixed> $chart
     */
    private function dtoFromChart(string $normalizedSymbol, array $chart): ?MarketDataDto
    {
        $meta = is_array($chart['meta'] ?? null) ? $chart['meta'] : [];
        $quote = is_array($chart['indicators']['quote'][0] ?? null)
            ? $chart['indicators']['quote'][0]
            : [];
        $closes = is_array($quote['close'] ?? null) ? $quote['close'] : [];
        $opens = is_array($quote['open'] ?? null) ? $quote['open'] : [];
        $highs = is_array($quote['high'] ?? null) ? $quote['high'] : [];
        $lows = is_array($quote['low'] ?? null) ? $quote['low'] : [];
        $volumes = is_array($quote['volume'] ?? null) ? $quote['volume'] : [];

        $lastClose = $this->lastNumeric($closes);
        $price = $this->positiveFloat($meta['regularMarketPrice'] ?? null) ?? $lastClose;
        if ($price === null) {
            return null;
        }

        $open = $this->positiveFloat($meta['regularMarketOpen'] ?? null)
            ?? $this->firstNumeric($opens)
            ?? $price;
        $high = $this->positiveFloat($meta['regularMarketDayHigh'] ?? null)
            ?? $this->maxNumeric($highs)
            ?? $price;
        $low = $this->positiveFloat($meta['regularMarketDayLow'] ?? null)
            ?? $this->minNumeric($lows)
            ?? $price;
        $previousClose = $this->positiveFloat($meta['chartPreviousClose'] ?? null)
            ?? $this->positiveFloat($meta['previousClose'] ?? null)
            ?? $price;
        $volume = is_numeric($meta['regularMarketVolume'] ?? null)
            ? max(0, (int) $meta['regularMarketVolume'])
            : (int) max(0.0, $this->lastNumeric($volumes) ?? 0.0);

        return new MarketDataDto(
            symbol: $this->bareSymbol($normalizedSymbol),
            price: $price,
            open: $open,
            high: $high,
            low: $low,
            previousClose: $previousClose,
            volume: $volume,
            fetchedAt: new \DateTimeImmutable(),
        );
    }

    /** @param mixed[] $values */
    private function firstNumeric(array $values): ?float
    {
        foreach ($values as $value) {
            if (is_numeric($value) && (float) $value > 0.0) {
                return (float) $value;
            }
        }

        return null;
    }

    /** @param mixed[] $values */
    private function lastNumeric(array $values): ?float
    {
        for ($index = count($values) - 1; $index >= 0; --$index) {
            if (is_numeric($values[$index]) && (float) $values[$index] > 0.0) {
                return (float) $values[$index];
            }
        }

        return null;
    }

    /** @param mixed[] $values */
    private function maxNumeric(array $values): ?float
    {
        $numeric = array_map('floatval', array_filter($values, static fn(mixed $value): bool => is_numeric($value) && (float) $value > 0.0));

        return $numeric === [] ? null : max($numeric);
    }

    /** @param mixed[] $values */
    private function minNumeric(array $values): ?float
    {
        $numeric = array_map('floatval', array_filter($values, static fn(mixed $value): bool => is_numeric($value) && (float) $value > 0.0));

        return $numeric === [] ? null : min($numeric);
    }

    private function positiveFloat(mixed $value): ?float
    {
        return is_numeric($value) && (float) $value > 0.0 ? (float) $value : null;
    }

    private function backoffSeconds(int $attempt): int
    {
        return min((int) (2 ** $attempt) * 2, 30) + random_int(1, 5);
    }

    private function normalizeSymbol(string $symbol): string
    {
        $symbol = strtoupper(trim($symbol));
        $bareSymbol = str_ends_with($symbol, '.IS') ? substr($symbol, 0, -3) : $symbol;

        if (!preg_match('/^[A-Z0-9]{2,20}$/', $bareSymbol)) {
            throw new \InvalidArgumentException('Gecersiz BIST sembolu.');
        }

        return $bareSymbol . '.IS';
    }

    private function bareSymbol(string $symbol): string
    {
        return strtoupper(str_replace('.IS', '', $symbol));
    }

    private function cacheKey(string $symbol): string
    {
        return 'yahoo.quote.' . strtolower(str_replace(['.', ' '], '_', $symbol));
    }

    private function lastSuccessCacheKey(string $symbol): string
    {
        return 'yahoo.last_success.' . strtolower(str_replace(['.', ' '], '_', $symbol));
    }

    private function blockKey(string $symbol): string
    {
        return 'yahoo.block.' . strtolower(str_replace(['.', ' '], '_', $symbol));
    }
}
