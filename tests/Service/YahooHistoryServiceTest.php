<?php

namespace App\Tests\Service;

use App\Service\YahooHistoryService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

class YahooHistoryServiceTest extends TestCase
{
    public function testRateLimitOpensSharedCircuitBeforeNextSymbol(): void
    {
        $requestCount = 0;
        $client = new MockHttpClient(function () use (&$requestCount): MockResponse {
            $requestCount++;
            return new MockResponse('{"chart":{"result":null}}', ['http_code' => 429]);
        });
        $service = new YahooHistoryService(
            $client,
            new ArrayAdapter(),
            new LockFactory(new InMemoryStore()),
            new NullLogger(),
        );

        $first = $service->fetch('ASELS');
        $second = $service->fetch('THYAO');

        self::assertSame(1, $requestCount);
        self::assertSame('rate_limited', $first['status']);
        self::assertSame('rate_limited', $second['status']);
        self::assertSame(429, $second['httpStatus']);
    }

    public function testBatchHistoryLoadsMultipleSymbolsWithOneRequest(): void
    {
        $requestCount = 0;
        $client = new MockHttpClient(function (string $method, string $url) use (&$requestCount): MockResponse {
            ++$requestCount;
            self::assertStringContainsString('ASELS.IS%2CTHYAO.IS', $url);

            return new MockResponse(json_encode([
                'spark' => [
                    'result' => [
                        $this->historyEntry('ASELS.IS', 100.0),
                        $this->historyEntry('THYAO.IS', 300.0),
                    ],
                ],
            ], JSON_THROW_ON_ERROR), ['http_code' => 200]);
        });
        $service = new YahooHistoryService(
            $client,
            new ArrayAdapter(),
            new LockFactory(new InMemoryStore()),
            new NullLogger(),
        );

        $results = $service->fetchBatch(['ASELS', 'THYAO']);

        self::assertSame(1, $requestCount);
        self::assertSame('api_batch', $results['ASELS']['source']);
        self::assertSame('ok', $results['THYAO']['status']);
        self::assertCount(25, $results['ASELS']['bars']);
        self::assertSame(324.0, $results['THYAO']['bars'][24]['close']);
    }

    /** @return array<string, mixed> */
    private function historyEntry(string $symbol, float $start): array
    {
        $timestamps = [];
        $open = [];
        $high = [];
        $low = [];
        $close = [];
        $volume = [];

        for ($index = 0; $index < 25; ++$index) {
            $timestamps[] = 1_700_000_000 + ($index * 86400);
            $open[] = $start + $index - 0.5;
            $high[] = $start + $index + 1.0;
            $low[] = $start + $index - 1.0;
            $close[] = $start + $index;
            $volume[] = 1_000_000 + $index;
        }

        return [
            'symbol' => $symbol,
            'response' => [[
                'meta' => ['exchangeTimezoneName' => 'Europe/Istanbul'],
                'timestamp' => $timestamps,
                'indicators' => ['quote' => [[
                    'open' => $open,
                    'high' => $high,
                    'low' => $low,
                    'close' => $close,
                    'volume' => $volume,
                ]]],
            ]],
        ];
    }
}
