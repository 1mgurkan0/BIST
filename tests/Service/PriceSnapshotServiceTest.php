<?php

namespace App\Tests\Service;

use App\DTO\MarketDataDto;
use App\Repository\PortfolioRepository;
use App\Repository\PriceAlertRepository;
use App\Repository\WatchlistItemRepository;
use App\Service\PriceSnapshotService;
use App\Service\YahooFinanceService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

class PriceSnapshotServiceTest extends TestCase
{
    public function testOldSuccessfulSnapshotIsMarkedStale(): void
    {
        $cache = new ArrayAdapter();
        $item = $cache->getItem(PriceSnapshotService::CACHE_KEY);
        $item->set([
            'status' => 'ok',
            'message' => 'ok',
            'fetchedAt' => (new \DateTimeImmutable('-2 hours'))->format(\DateTimeInterface::ATOM),
            'updatedAt' => (new \DateTimeImmutable('-2 hours'))->format(\DateTimeInterface::ATOM),
            'summary' => [],
            'items' => [
                'ASELS' => [
                    'symbol' => 'ASELS',
                    'price' => 100.0,
                    'quoteStatus' => 'ok',
                    'status' => 'ok',
                    'isStale' => false,
                    'fetchedAt' => (new \DateTimeImmutable('-2 hours'))->format(\DateTimeInterface::ATOM),
                ],
            ],
        ]);
        $cache->save($item);

        $service = new PriceSnapshotService(
            $cache,
            $this->createStub(YahooFinanceService::class),
            $this->createStub(PortfolioRepository::class),
            $this->createStub(WatchlistItemRepository::class),
            $this->createStub(PriceAlertRepository::class),
            new NullLogger(),
        );

        $result = $service->itemsForSymbols(['ASELS']);

        self::assertTrue($result['ASELS']['isStale']);
        self::assertSame('stale_snapshot', $result['ASELS']['quoteStatus']);
    }

    public function testPartialRefreshMergesWithoutErasingExistingSymbols(): void
    {
        $cache = new ArrayAdapter();
        $existing = $cache->getItem(PriceSnapshotService::CACHE_KEY);
        $existing->set([
            'status' => 'ok',
            'message' => 'ok',
            'fetchedAt' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'updatedAt' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'symbols' => ['ASELS'],
            'summary' => ['total' => 1, 'fresh' => 1, 'stale' => 0, 'missing' => 0, 'rateLimited' => 0],
            'items' => [
                'ASELS' => [
                    'symbol' => 'ASELS',
                    'price' => 100.0,
                    'quoteStatus' => 'ok',
                    'status' => 'ok',
                    'isStale' => false,
                    'fetchedAt' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
                ],
            ],
        ]);
        $cache->save($existing);

        $dto = new MarketDataDto('THYAO', 320.0, 322.0, 325.0, 318.0, 328.0, 2_000_000, new \DateTimeImmutable());
        $yahoo = $this->createStub(YahooFinanceService::class);
        $yahoo->method('fetchBatchWithStatus')->willReturn([
            'THYAO' => [
                'symbol' => 'THYAO',
                'data' => $dto,
                'lastSuccessful' => $dto,
                'source' => 'api_batch',
                'status' => 'ok',
                'httpStatus' => null,
                'message' => null,
                'isStale' => false,
            ],
        ]);

        $service = new PriceSnapshotService(
            $cache,
            $yahoo,
            $this->createStub(PortfolioRepository::class),
            $this->createStub(WatchlistItemRepository::class),
            $this->createStub(PriceAlertRepository::class),
            new NullLogger(),
        );

        $partial = $service->refresh(['THYAO']);
        $saved = $service->snapshot();

        self::assertSame(['THYAO'], array_keys($partial['items']));
        self::assertSame(['ASELS', 'THYAO'], array_keys($saved['items']));
        self::assertSame(2, $saved['summary']['fresh']);
    }
}
