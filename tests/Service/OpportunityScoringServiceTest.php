<?php

namespace App\Tests\Service;

use App\Service\OpportunityScoringService;
use PHPUnit\Framework\TestCase;

class OpportunityScoringServiceTest extends TestCase
{
    public function testPositiveConfirmedMomentumRanksAboveWeakSetup(): void
    {
        $service = new OpportunityScoringService();
        $positive = $service->score($this->technical('pozitif', 58, 1.5, 30, 2.0), $this->history());
        $weak = $service->score($this->technical('negatif', 30, -1.0, 70, 0.7), $this->history());

        self::assertSame('eligible', $positive['status']);
        self::assertGreaterThan($weak['score'], $positive['score']);
        self::assertGreaterThanOrEqual(70, $positive['score']);
    }

    public function testStaleHistoryCannotBecomeEligible(): void
    {
        $service = new OpportunityScoringService();
        $history = $this->history();
        $history['isStale'] = true;
        $history['status'] = 'rate_limited';

        $result = $service->score($this->technical('pozitif', 58, 1.5, 30, 2.0), $history);

        self::assertSame('stale', $result['status']);
        self::assertContains('Tarihsel veri stale; AI adayligina alinmadi.', $result['reasons']);
    }

    public function testMissingTechnicalHistoryGetsZeroScore(): void
    {
        $result = (new OpportunityScoringService())->score(
            ['status' => 'insufficient_history'],
            $this->history()
        );

        self::assertSame(0, $result['score']);
        self::assertSame('missing', $result['status']);
    }

    /** @return array<string, mixed> */
    private function technical(string $trend, float $rsi, float $macd, float $volatility, float $volume): array
    {
        return [
            'status' => 'ok',
            'trend' => $trend,
            'lastClose' => 120,
            'returns' => ['1w' => 4, '1m' => 12, '3m' => 24],
            'rsi14' => $rsi,
            'macd' => ['histogram' => $macd],
            'sma' => ['20' => 112, '50' => 105, '200' => 90],
            'volumeRatio20' => $volume,
            'volatility20' => $volatility,
        ];
    }

    /** @return array<string, mixed> */
    private function history(): array
    {
        return ['status' => 'ok', 'isStale' => false];
    }
}
