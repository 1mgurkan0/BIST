<?php

namespace App\Tests\Command;

use App\Command\AlertCheckCommand;
use App\Entity\PriceAlert;
use App\Repository\PriceAlertRepository;
use App\Service\PriceSnapshotService;
use App\Service\TelegramService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

class AlertCheckCommandTest extends TestCase
{
    public function testStaleQuoteNeverTriggersAlertOrTelegram(): void
    {
        $alert = (new PriceAlert())
            ->setSymbol('ASELS')
            ->setConditionType(PriceAlert::TYPE_PRICE_ABOVE)
            ->setTargetValue(100);

        $repository = $this->createStub(PriceAlertRepository::class);
        $repository->method('findActive')->willReturn([$alert]);

        $snapshot = $this->createStub(PriceSnapshotService::class);
        $snapshot->method('itemsForSymbols')->willReturn([
            'ASELS' => [
                'price' => 110.0,
                'dailyChangePercent' => 2.5,
                'quoteStatus' => 'rate_limited',
                'httpStatus' => 429,
                'isStale' => true,
            ],
        ]);

        $telegram = $this->createMock(TelegramService::class);
        $telegram->expects(self::never())->method('sendMessage');

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('flush');

        $tester = new CommandTester(new AlertCheckCommand(
            $repository,
            $snapshot,
            $telegram,
            $entityManager,
            new NullLogger(),
            new LockFactory(new InMemoryStore()),
        ));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertTrue($alert->isActive());
        self::assertFalse($alert->isTriggered());
        self::assertSame(110.0, $alert->getLastPrice());
        self::assertStringContainsString('Stale veri, tetiklenmedi', $tester->getDisplay());
    }

    public function testTelegramFailureKeepsTriggeredAlertActiveForRetry(): void
    {
        $alert = (new PriceAlert())
            ->setSymbol('ASELS')
            ->setConditionType(PriceAlert::TYPE_PRICE_ABOVE)
            ->setTargetValue(100);

        $repository = $this->createStub(PriceAlertRepository::class);
        $repository->method('findActive')->willReturn([$alert]);

        $snapshot = $this->createStub(PriceSnapshotService::class);
        $snapshot->method('itemsForSymbols')->willReturn([
            'ASELS' => [
                'price' => 110.0,
                'dailyChangePercent' => 2.5,
                'quoteStatus' => 'ok',
                'httpStatus' => null,
                'isStale' => false,
            ],
        ]);

        $telegram = $this->createMock(TelegramService::class);
        $telegram->expects(self::once())->method('sendMessage')->willReturn(false);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('flush');

        $tester = new CommandTester(new AlertCheckCommand(
            $repository,
            $snapshot,
            $telegram,
            $entityManager,
            new NullLogger(),
            new LockFactory(new InMemoryStore()),
        ));

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertTrue($alert->isActive());
        self::assertFalse($alert->isTriggered());
        self::assertSame(110.0, $alert->getLastPrice());
        self::assertStringContainsString('Telegram hata, alarm aktif', $tester->getDisplay());
    }
}
