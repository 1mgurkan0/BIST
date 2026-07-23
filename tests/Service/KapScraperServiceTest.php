<?php

namespace App\Tests\Service;

use App\Entity\KapNews;
use App\Repository\KapNewsRepository;
use App\Service\KapScraperService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

class KapScraperServiceTest extends TestCase
{
    public function testItPersistsOnlyRequestedSymbols(): void
    {
        $payload = [
            ['disclosureBasic' => [
                'disclosureIndex' => 123,
                'stockCode' => 'ASELS',
                'relatedStocks' => null,
                'publishDate' => '21.07.2026 18:00:00',
                'companyTitle' => 'ASELSAN',
                'title' => 'Finansal Takvim',
                'summary' => 'Takvim aciklandi.',
            ]],
            ['disclosureBasic' => [
                'disclosureIndex' => 124,
                'stockCode' => 'THYAO',
                'relatedStocks' => null,
                'publishDate' => '21.07.2026 18:01:00',
                'companyTitle' => 'THY',
                'title' => 'Ozel Durum',
                'summary' => 'Aciklama.',
            ]],
        ];

        $client = new MockHttpClient(new MockResponse(
            json_encode($payload, JSON_THROW_ON_ERROR),
            ['http_code' => 200]
        ));
        $repository = $this->getMockBuilder(KapNewsRepository::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['findExistingKapIds'])
            ->getMock();
        $repository->expects(self::once())->method('findExistingKapIds')->with(['123'])->willReturn([]);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())
            ->method('persist')
            ->with(self::callback(static fn(mixed $news): bool => $news instanceof KapNews
                && $news->getKapId() === '123'
                && $news->getStockCodes() === ['ASELS']));
        $entityManager->expects(self::once())->method('flush');

        $service = new KapScraperService(
            $client,
            $entityManager,
            $repository,
            new NullLogger(),
            new LockFactory(new InMemoryStore()),
        );

        $summary = $service->fetchAndSaveLatest(['ASELS'], 2, false);

        self::assertSame(2, $summary['received']);
        self::assertSame(1, $summary['matched']);
        self::assertSame(1, $summary['created']);
        self::assertSame(1, $summary['skipped']);
    }
}
