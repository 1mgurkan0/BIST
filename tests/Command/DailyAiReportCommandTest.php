<?php

namespace App\Tests\Command;

use App\Command\DailyAiReportCommand;
use App\Repository\KapNewsRepository;
use App\Repository\OpportunityCandidateRepository;
use App\Repository\PortfolioRepository;
use App\Repository\WatchlistItemRepository;
use App\Service\GeminiService;
use App\Service\PriceSnapshotService;
use App\Service\TechnicalAnalysisService;
use App\Service\TelegramService;
use App\Service\YahooHistoryService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

class DailyAiReportCommandTest extends TestCase
{
    public function testInvalidGeminiJsonFallsBackWithoutBreakingTheReportRun(): void
    {
        $priceSnapshot = $this->createStub(PriceSnapshotService::class);
        $priceSnapshot->method('itemsForSymbols')->willReturn([
            'ASELS' => [
                'price' => 100.0,
                'dailyChangePercent' => 1.5,
                'quoteStatus' => 'ok',
                'source' => 'api',
                'isStale' => false,
            ],
        ]);

        $history = $this->createStub(YahooHistoryService::class);
        $history->method('fetchBatch')->willReturn([
            'ASELS' => [
                'status' => 'missing_history',
                'source' => 'none',
                'isStale' => false,
                'bars' => [],
            ],
        ]);

        $technical = $this->createStub(TechnicalAnalysisService::class);
        $technical->method('analyze')->willReturn([
            'status' => 'insufficient_data',
            'bars' => 0,
        ]);

        $portfolio = $this->createStub(PortfolioRepository::class);
        $portfolio->method('findAll')->willReturn([]);
        $watchlist = $this->createStub(WatchlistItemRepository::class);
        $watchlist->method('findActive')->willReturn([]);
        $opportunities = $this->createStub(OpportunityCandidateRepository::class);
        $kapNews = $this->createStub(KapNewsRepository::class);
        $kapNews->method('findRecentForSymbol')->willReturn([]);

        $gemini = $this->createMock(GeminiService::class);
        $gemini->expects(self::exactly(2))
            ->method('askJson')
            ->willReturnOnConsecutiveCalls('not-json', 'still-not-json');

        $telegram = $this->createMock(TelegramService::class);
        $telegram->expects(self::never())->method('sendMessage');

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('persist');
        $entityManager->expects(self::never())->method('flush');

        $command = new DailyAiReportCommand(
            $priceSnapshot,
            $history,
            $technical,
            $portfolio,
            $watchlist,
            $opportunities,
            $kapNews,
            $gemini,
            $telegram,
            $entityManager,
            new NullLogger(),
            new LockFactory(new InMemoryStore()),
        );
        $tester = new CommandTester($command);

        $status = $tester->execute([
            '--symbol' => ['ASELS'],
            '--dry-run' => true,
            '--skip-price-refresh' => true,
            '--delay' => '0',
        ]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertStringContainsString('fallback_json', $tester->getDisplay());
        self::assertStringContainsString('fallback: 1', $tester->getDisplay());
    }
}
