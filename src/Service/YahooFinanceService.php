<?php

namespace App\Service;

use App\DTO\MarketDataDto;
use Psr\Log\LoggerInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class YahooFinanceService
{
    private const BASE_URL      = 'https://query1.finance.yahoo.com/v8/finance/chart/%s?interval=1d&range=1d';
    private const SYMBOL_TTL    = 60;
    private const BLOCK_DURATION = 600;
    private const REQUEST_DELAY = 2_200_000;
    private const MAX_RETRY     = 3;

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
        private readonly CacheInterface      $cache,
        private readonly LockFactory         $lockFactory,
        private readonly LoggerInterface     $logger,
    ) {}

    /**
     * Tek sembol çeker. Cache'de varsa HTTP isteği atmaz.
     */
    public function fetchOne(string $symbol): ?MarketDataDto
    {
        $symbol = $this->normalizeSymbol($symbol);

        $cached = $this->getFromCache($symbol);
        if ($cached !== null) {
            return $cached;
        }

        if ($this->isBlocked($symbol)) {
            $this->logger->notice('Sembol bloke, atlanıyor.', ['symbol' => $symbol]);
            return null;
        }

        return $this->fetchFromApi($symbol);
    }

    /**
     * Sembol listesini sırayla çeker.
     * Cache'dekiler anında döner, eksikler API'den teker teker alınır.
     *
     * @param  string[]                     $symbols
     * @return array<string, MarketDataDto>
     */
    public function fetchBatch(array $symbols): array
    {
        if (empty($symbols)) {
            return [];
        }

        $results  = [];
        $missing  = [];

        foreach ($symbols as $raw) {
            $symbol = $this->normalizeSymbol($raw);
            $cached = $this->getFromCache($symbol);

            if ($cached !== null) {
                $results[$cached->symbol] = $cached;
                $this->logger->debug('Cache hit.', ['symbol' => $symbol]);
            } else {
                $missing[] = $symbol;
            }
        }

        if (empty($missing)) {
            $this->logger->info('Tüm semboller cache\'den karşılandı.', ['count' => count($results)]);
            return $results;
        }

        $this->logger->info('API\'den çekilecek semboller.', [
            'toplam'    => count($missing),
            'semboller' => implode(', ', $missing),
        ]);

        foreach ($missing as $index => $symbol) {
            if ($this->isBlocked($symbol)) {
                $this->logger->notice('Sembol bloke, atlanıyor.', ['symbol' => $symbol]);
                continue;
            }

            $dto = $this->fetchFromApi($symbol);

            if ($dto !== null) {
                $results[$dto->symbol] = $dto;
            }

            if ($index < count($missing) - 1) {
                $jitter = random_int(0, 800_000); // 0–0.8 sn ek jitter
                usleep(self::REQUEST_DELAY + $jitter);
            }
        }

        $this->logger->info('fetchBatch tamamlandı.', [
            'istenen'  => count($symbols),
            'dönen'    => count($results),
            'başarısız' => count($missing) - (count($results) - count($symbols) + count($missing)),
        ]);

        return $results;
    }

    private function fetchFromApi(string $normalizedSymbol): ?MarketDataDto
    {
        $lock = $this->lockFactory->createLock('yahoo_fetch_' . $normalizedSymbol, 15.0, false);

        if (!$lock->acquire()) {
            usleep(500_000);
            return $this->getFromCache($normalizedSymbol);
        }

        try {
            return $this->doRequest($normalizedSymbol);
        } finally {
            $lock->release();
        }
    }

    private function doRequest(string $symbol): ?MarketDataDto
    {
        $url = sprintf(self::BASE_URL, $symbol);

        for ($attempt = 0; $attempt < self::MAX_RETRY; $attempt++) {
            $ua      = self::USER_AGENTS[array_rand(self::USER_AGENTS)];
            $lang    = self::ACCEPT_LANGUAGES[array_rand(self::ACCEPT_LANGUAGES)];
            $referer = self::REFERERS[array_rand(self::REFERERS)];

            try {
                $response   = $this->client->request('GET', $url, [
                    'headers' => [
                        'User-Agent'      => $ua,
                        'Accept'          => 'application/json, text/plain, */*',
                        'Accept-Language' => $lang,
                        'Accept-Encoding' => 'gzip, deflate, br',
                        'Referer'         => $referer,
                        'Origin'          => 'https://finance.yahoo.com',
                        'Connection'      => 'keep-alive',
                        'Sec-Fetch-Dest'  => 'empty',
                        'Sec-Fetch-Mode'  => 'cors',
                        'Sec-Fetch-Site'  => 'same-site',
                    ],
                    'timeout' => 10,
                ]);

                $status = $response->getStatusCode();

                if ($status === 429) {
                    $wait = $this->backoffSeconds($attempt);
                    $this->logger->warning('429 alındı, backoff.', [
                        'symbol'     => $symbol,
                        'deneme'     => $attempt + 1,
                        'bekleme_sn' => $wait,
                    ]);
                    $this->block($symbol);
                    sleep($wait);
                    continue;
                }

                if ($status !== 200) {
                    $this->logger->error('Beklenmeyen HTTP kodu.', [
                        'symbol' => $symbol,
                        'status' => $status,
                    ]);
                    return null;
                }

                $data = $response->toArray();

                if (empty($data['chart']['result'][0])) {
                    $this->logger->notice('Sembol için veri yok.', ['symbol' => $symbol]);
                    return null;
                }

                $result = $data['chart']['result'][0];
                $meta   = $result['meta'];
                $quote  = $result['indicators']['quote'][0];
                $last   = count($quote['close']) - 1;

                $dto = new MarketDataDto(
                    symbol:        $this->bareSymbol($symbol),
                    price:         (float) ($meta['regularMarketPrice']   ?? 0.0),
                    open:          (float) ($quote['open'][$last]         ?? 0.0),
                    high:          (float) ($quote['high'][$last]         ?? 0.0),
                    low:           (float) ($quote['low'][$last]          ?? 0.0),
                    previousClose: (float) ($meta['chartPreviousClose']   ?? 0.0),
                    volume:        (int)   ($quote['volume'][$last]       ?? 0),
                    fetchedAt:     new \DateTimeImmutable(),
                );

                $this->saveToCache($symbol, $dto);

                $this->logger->info('Veri alındı.', [
                    'symbol' => $dto->symbol,
                    'price'  => $dto->price,
                ]);

                return $dto;

            } catch (\Throwable $e) {
                $this->logger->error('İstek hatası.', [
                    'symbol' => $symbol,
                    'hata'   => $e->getMessage(),
                ]);
                return null;
            }
        }

        $this->logger->error('Retry sınırı aşıldı.', ['symbol' => $symbol]);
        return null;
    }

    private function getFromCache(string $normalizedSymbol): ?MarketDataDto
    {
        try {
            $item = $this->cache->getItem($this->cacheKey($normalizedSymbol));
            if ($item->isHit()) {
                /** @var MarketDataDto $dto */
                $dto = $item->get();
                return $dto;
            }
        } catch (\Throwable) {}
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
            $this->logger->warning('Cache yazma hatası.', ['symbol' => $normalizedSymbol, 'hata' => $e->getMessage()]);
        }
    }

    private function block(string $symbol): void
    {
        try {
            $item = $this->cache->getItem($this->blockKey($symbol));
            $item->set(true);
            $item->expiresAfter(self::BLOCK_DURATION);
            $this->cache->save($item);
        } catch (\Throwable) {}
    }

    private function isBlocked(string $symbol): bool
    {
        try {
            return $this->cache->getItem($this->blockKey($symbol))->isHit();
        } catch (\Throwable) {
            return false;
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

    private function cacheKey(string $s): string
    {
        return 'yahoo.quote.' . strtolower(str_replace(['.', ' '], '_', $s));
    }

    private function blockKey(string $s): string
    {
        return 'yahoo.block.' . strtolower(str_replace(['.', ' '], '_', $s));
    }
}
