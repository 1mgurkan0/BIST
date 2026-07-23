<?php

namespace App\Tests\Command;

use App\Command\KapAnalyzerCommand;
use App\Entity\KapNews;
use App\Repository\KapNewsRepository;
use App\Service\GeminiService;
use App\Service\PriceSnapshotService;
use App\Service\TelegramService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

class KapAnalyzerCommandTest extends TestCase
{
    public function testDryRunNeverPersistsOrSendsTelegram(): void
    {
        $news = (new KapNews())
            ->setKapId('1234567')
            ->setTitle('Test bildirimi')
            ->setContent('Sirket yeni bir sozlesme imzaladi.')
            ->setStockCodes(['ASELS']);

        $repository = $this->createStub(KapNewsRepository::class);
        $repository->method('findUnanalyzedForSymbols')->willReturn([$news]);

        $gemini = $this->createMock(GeminiService::class);
        $gemini->expects(self::once())->method('askJson')->willReturn(json_encode([
            'score' => 75,
            'summary' => 'Olumlu haber.',
            'reason' => 'Yeni sozlesme.',
            'short_term' => 'Pozitif etki beklenebilir.',
            'long_term' => 'Katki sozlesme buyuklugune bagli.',
            'risks' => 'Teslimat riski.',
        ], JSON_THROW_ON_ERROR));

        $telegram = $this->createMock(TelegramService::class);
        $telegram->expects(self::never())->method('sendMessage');

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('flush');

        $tester = new CommandTester(new KapAnalyzerCommand(
            $repository,
            $gemini,
            $entityManager,
            $telegram,
            new NullLogger(),
            new LockFactory(new InMemoryStore()),
            $this->trackedPriceSnapshot(),
        ));

        $status = $tester->execute([
            '--dry-run' => true,
            '--limit' => '1',
            '--delay' => '0',
        ]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertFalse($news->isAnalyzed());
        self::assertStringContainsString('Dry-run', $tester->getDisplay());
        self::assertStringContainsString('75', $tester->getDisplay());
    }

    public function testTelegramFailureLeavesNewsPendingForRetry(): void
    {
        $news = (new KapNews())
            ->setKapId('7654321')
            ->setTitle('Onemli test bildirimi')
            ->setContent('Yuksek etkili bir gelisme aciklandi.')
            ->setStockCodes(['ASELS']);

        $repository = $this->createStub(KapNewsRepository::class);
        $repository->method('findUnanalyzedForSymbols')->willReturn([$news]);

        $gemini = $this->createStub(GeminiService::class);
        $gemini->method('askJson')->willReturn(json_encode([
            'score' => 90,
            'summary' => 'Olumlu haber.',
            'reason' => 'Guclu finansal etki.',
            'short_term' => 'Pozitif.',
            'long_term' => 'Pozitif.',
            'risks' => 'Uygulama riski.',
        ], JSON_THROW_ON_ERROR));

        $telegram = $this->createMock(TelegramService::class);
        $telegram->expects(self::once())->method('sendMessage')->willReturn(false);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('flush');

        $tester = new CommandTester(new KapAnalyzerCommand(
            $repository,
            $gemini,
            $entityManager,
            $telegram,
            new NullLogger(),
            new LockFactory(new InMemoryStore()),
            $this->trackedPriceSnapshot(),
        ));

        $status = $tester->execute([
            '--limit' => '1',
            '--threshold' => '75',
            '--delay' => '0',
        ]);

        self::assertSame(Command::FAILURE, $status);
        self::assertFalse($news->isAnalyzed());
        self::assertNull($news->getAnalyzedAt());
        self::assertStringContainsString('Telegram hatasi', $tester->getDisplay());
    }

    private function trackedPriceSnapshot(): PriceSnapshotService
    {
        $snapshot = $this->createStub(PriceSnapshotService::class);
        $snapshot->method('trackedSymbols')->willReturn(['ASELS']);

        return $snapshot;
    }
}
