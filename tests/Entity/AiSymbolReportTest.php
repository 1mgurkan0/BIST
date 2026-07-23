<?php

namespace App\Tests\Entity;

use App\Entity\AiSymbolReport;
use PHPUnit\Framework\TestCase;

class AiSymbolReportTest extends TestCase
{
    public function testScoreAndStatusesAreNormalized(): void
    {
        $report = (new AiSymbolReport())
            ->setScore(-20)
            ->setAnalysisStatus('invalid')
            ->setHistoryStatus('');

        self::assertSame(0, $report->getScore());
        self::assertSame(AiSymbolReport::ANALYSIS_FALLBACK_ERROR, $report->getAnalysisStatus());
        self::assertSame('missing_history', $report->getHistoryStatus());

        $report->setScore(120)->setAnalysisStatus(AiSymbolReport::ANALYSIS_SUCCESS);
        self::assertSame(100, $report->getScore());
        self::assertSame(AiSymbolReport::ANALYSIS_SUCCESS, $report->getAnalysisStatus());

        $report->setReportScope(AiSymbolReport::SCOPE_OPPORTUNITY);
        self::assertSame(AiSymbolReport::SCOPE_OPPORTUNITY, $report->getReportScope());
        $report->setReportScope('invalid');
        self::assertSame(AiSymbolReport::SCOPE_TRACKED, $report->getReportScope());
    }
}
