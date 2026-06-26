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
    private const SYMBOL_TTL = 60;
    private const LAST_SUCCESS_TTL = 604800;
    private const BLOCK_DURATION = 600;
    private const REQUEST_DELAY = 2_200_000;
    private const MAX_RETRY = 3;

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
        $symbol = $this->normalizeSymbol($symbol);

        $cached = $this->getFromCache($symbol);
        if ($cached !== null) {
            return $this->buildStatus($symbol, $cached, 'cache', 'ok');
        }

        if ($this->isBlocked($symbol)) {
            $this->logger->notice('Symbol is in Yahoo 429 block, using last successful quote.', ['symbol' => $symbol]);

            return $this->buildStatusFromLast(
                $symbol,
                'rate_limited',
                429,
                'Yahoo 429 limiti aktif. Son basarili veri gosteriliyor.'
            );
        }

        return $this->fetchFromApiWithStatus($symbol);
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
                $meta = $result['meta'] ?? [];
                $quote = $result['indicators']['quote'][0] ?? [];
                $closeValues = $quote['close'] ?? [];
                $last = max(count($closeValues) - 1, 0);

                $dto = new MarketDataDto(
                    symbol: $this->bareSymbol($symbol),
                    price: (float) ($meta['regularMarketPrice'] ?? ($closeValues[$last] ?? 0.0)),
                    open: (float) (($quote['open'][$last] ?? null) ?? 0.0),
                    high: (float) (($quote['high'][$last] ?? null) ?? 0.0),
                    low: (float) (($quote['low'][$last] ?? null) ?? 0.0),
                    previousClose: (float) ($meta['chartPreviousClose'] ?? 0.0),
                    volume: (int) (($quote['volume'][$last] ?? null) ?? 0),
                    fetchedAt: new \DateTimeImmutable(),
                );

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

    private function backoffSeconds(int $attempt): int
    {
        return min((int) (2 ** $attempt) * 2, 30) + random_int(1, 5);
    }

    private function normalizeSymbol(string $symbol): string
    {
        $symbol = strtoupper(trim($symbol));

        return str_ends_with($symbol, '.IS') ? $symbol : $symbol . '.IS';
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
