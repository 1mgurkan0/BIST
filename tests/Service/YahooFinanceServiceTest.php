<?php

namespace App\Tests\Service;

use App\Service\YahooFinanceService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

class YahooFinanceServiceTest extends TestCase
{
    public function testRateLimitOpensCircuitForRemainingBatchSymbols(): void
    {
        $requestCount = 0;
        $client = new MockHttpClient(function () use (&$requestCount): MockResponse {
            $requestCount++;

            return new MockResponse('{"chart":{"result":null}}', ['http_code' => 429]);
        });
        $service = new YahooFinanceService(
            $client,
            new ArrayAdapter(),
            new LockFactory(new InMemoryStore()),
            new NullLogger(),
        );

        $results = $service->fetchBatchWithStatus(['ASELS', 'THYAO']);

        self::assertSame(1, $requestCount);
        self::assertSame(429, $results['ASELS']['httpStatus']);
        self::assertSame(429, $results['THYAO']['httpStatus']);
        self::assertSame('rate_limited', $results['THYAO']['status']);
    }

    public function testInvalidSymbolIsRejectedBeforeRequest(): void
    {
        $service = new YahooFinanceService(
            new MockHttpClient(),
            new ArrayAdapter(),
            new LockFactory(new InMemoryStore()),
            new NullLogger(),
        );

        $this->expectException(\InvalidArgumentException::class);
        $service->fetchOneWithStatus('../ASELS');
    }

    public function testBatchResponseProvidesEverySymbolFromOneRequest(): void
    {
        $requestCount = 0;
        $client = new MockHttpClient(function (string $method, string $url) use (&$requestCount): MockResponse {
            ++$requestCount;
            self::assertStringContainsString('ASELS.IS%2CTHYAO.IS', $url);

            return new MockResponse(json_encode([
                'spark' => [
                    'result' => [
                        $this->sparkEntry('ASELS.IS', 100.5, 99.0, 102.0, 98.0, 99.5, 1_000_000),
                        $this->sparkEntry('THYAO.IS', 320.0, 325.0, 326.0, 318.0, 328.0, 2_000_000),
                    ],
                ],
            ], JSON_THROW_ON_ERROR), ['http_code' => 200]);
        });
        $service = new YahooFinanceService(
            $client,
            new ArrayAdapter(),
            new LockFactory(new InMemoryStore()),
            new NullLogger(),
        );

        $results = $service->fetchBatchWithStatus(['ASELS', 'THYAO']);

        self::assertSame(1, $requestCount);
        self::assertSame(100.5, $results['ASELS']['data']->price);
        self::assertSame(320.0, $results['THYAO']['data']->price);
        self::assertSame('api_batch', $results['ASELS']['source']);
        self::assertSame('ok', $results['THYAO']['status']);
    }

    public function testConcurrentBatchDoesNotFallBackToIndividualRequests(): void
    {
        $store = new InMemoryStore();
        $lockFactory = new LockFactory($store);
        $activeLock = $lockFactory->createLock('yahoo_batch_quote', 20.0, false);
        self::assertTrue($activeLock->acquire());

        $client = new MockHttpClient(static function (): MockResponse {
            self::fail('Yahoo must not be called while another batch owns the lock.');
        });
        $service = new YahooFinanceService(
            $client,
            new ArrayAdapter(),
            $lockFactory,
            new NullLogger(),
        );

        try {
            $results = $service->fetchBatchWithStatus(['ASELS', 'THYAO']);
        } finally {
            $activeLock->release();
        }

        self::assertSame('locked', $results['ASELS']['status']);
        self::assertSame('locked', $results['THYAO']['status']);
        self::assertNull($results['ASELS']['data']);
    }

    /**
     * @return array<string, mixed>
     */
    private function sparkEntry(
        string $symbol,
        float $price,
        float $open,
        float $high,
        float $low,
        float $previousClose,
        int $volume,
    ): array {
        return [
            'symbol' => $symbol,
            'response' => [[
                'meta' => [
                    'regularMarketPrice' => $price,
                    'regularMarketDayHigh' => $high,
                    'regularMarketDayLow' => $low,
                    'regularMarketVolume' => $volume,
                    'chartPreviousClose' => $previousClose,
                ],
                'indicators' => [
                    'quote' => [[
                        'open' => [$open],
                        'high' => [$high],
                        'low' => [$low],
                        'close' => [$price],
                        'volume' => [$volume],
                    ]],
                ],
            ]],
        ];
    }
}
