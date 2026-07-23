<?php

namespace App\Tests\Service;

use App\Service\TechnicalAnalysisService;
use PHPUnit\Framework\TestCase;

class TechnicalAnalysisServiceTest extends TestCase
{
    public function testItCalculatesIndicatorsForOneYearHistory(): void
    {
        $bars = [];
        $start = new \DateTimeImmutable('2025-01-01');
        for ($i = 0; $i < 260; $i++) {
            $close = 100 + ($i * 0.5);
            $bars[] = [
                'date' => $start->modify('+' . $i . ' days')->format('Y-m-d'),
                'open' => $close - 0.4,
                'high' => $close + 1.0,
                'low' => $close - 1.0,
                'close' => $close,
                'volume' => 1_000_000 + ($i * 1_000),
            ];
        }

        $result = (new TechnicalAnalysisService())->analyze($bars);

        self::assertSame('ok', $result['status']);
        self::assertSame(260, $result['bars']);
        self::assertSame('pozitif', $result['trend']);
        self::assertGreaterThan($result['sma']['50'], $result['sma']['20']);
        self::assertGreaterThan(50, $result['rsi14']);
        self::assertGreaterThan(0, $result['returns']['1y']);
        self::assertNotNull($result['macd']['histogram']);
        self::assertNotNull($result['levels']['support20']);
    }

    public function testItRejectsInsufficientHistory(): void
    {
        $result = (new TechnicalAnalysisService())->analyze([
            ['close' => 100.0],
            ['close' => 101.0],
        ]);

        self::assertSame('insufficient_history', $result['status']);
        self::assertSame(2, $result['bars']);
    }
}
