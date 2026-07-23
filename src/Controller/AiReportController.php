<?php

namespace App\Controller;

use App\Entity\AiSymbolReport;
use App\Repository\AiSymbolReportRepository;
use App\Service\PriceSnapshotService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/ai-reports')]
class AiReportController extends AbstractController
{
    #[Route('', name: 'app_ai_reports', methods: ['GET'])]
    public function index(
        Request $request,
        AiSymbolReportRepository $reportRepository,
        PriceSnapshotService $priceSnapshot,
    ): Response {
        $filter = (string) $request->query->get('filter', 'all');
        if (!in_array($filter, ['all', 'high', 'risky', 'kap', 'portfolio', 'opportunity'], true)) {
            $filter = 'all';
        }

        $reportScope = $filter === 'opportunity'
            ? AiSymbolReport::SCOPE_OPPORTUNITY
            : AiSymbolReport::SCOPE_TRACKED;
        $trackedSymbols = $priceSnapshot->trackedSymbols();
        $allReports = $reportScope === AiSymbolReport::SCOPE_OPPORTUNITY
            ? $reportRepository->findLatestDistinct(20, $reportScope)
            : array_values($reportRepository->findLatestBySymbols($trackedSymbols, $reportScope));

        if (empty($allReports)) {
            $allReports = $reportRepository->findLatestDistinct(30, $reportScope);
        }

        usort($allReports, fn(AiSymbolReport $a, AiSymbolReport $b): int => $b->getScore() <=> $a->getScore());

        $histories = [];
        $scoreChanges = [];
        foreach ($allReports as $report) {
            $history = $reportRepository->findHistoryForSymbol($report->getSymbol(), 7, $reportScope);
            $histories[$report->getSymbol()] = $history;
            $scoreChanges[$report->getSymbol()] = count($history) > 1
                ? $report->getScore() - $history[1]->getScore()
                : null;
        }

        $reports = array_values(array_filter(
            $allReports,
            fn(AiSymbolReport $report): bool => $this->matchesFilter($report, $filter)
        ));

        return $this->render('User/ai_report/index.html.twig', [
            'reports' => $reports,
            'stats' => $this->stats($allReports),
            'latestGeneratedAt' => $this->latestGeneratedAt($allReports),
            'activeFilter' => $filter,
            'histories' => $histories,
            'scoreChanges' => $scoreChanges,
            'reportScope' => $reportScope,
        ]);
    }

    private function matchesFilter(AiSymbolReport $report, string $filter): bool
    {
        return match ($filter) {
            'high' => $report->getScore() >= 70
                && $report->getAnalysisStatus() === AiSymbolReport::ANALYSIS_SUCCESS
                && !$report->isPriceStale()
                && $report->getHistoryStatus() === 'ok'
                && $report->getConfidence() !== 'dusuk',
            'risky' => $report->getScore() <= 40
                || $report->getDecisionLabel() === AiSymbolReport::DECISION_RISKY
                || $report->isPriceStale()
                || str_starts_with($report->getAnalysisStatus(), 'fallback_'),
            'kap' => count($report->getKapNewsIds()) > 0,
            'portfolio' => $report->isPortfolio(),
            'opportunity' => $report->getReportScope() === AiSymbolReport::SCOPE_OPPORTUNITY,
            default => true,
        };
    }

    /**
     * @param AiSymbolReport[] $reports
     * @return array<string, int>
     */
    private function stats(array $reports): array
    {
        $stats = [
            'total' => count($reports),
            'follow' => 0,
            'wait' => 0,
            'risky' => 0,
        ];

        foreach ($reports as $report) {
            if (!$report instanceof AiSymbolReport) {
                continue;
            }

            if ($report->getDecisionLabel() === AiSymbolReport::DECISION_FOLLOW) {
                $stats['follow']++;
            } elseif ($report->getDecisionLabel() === AiSymbolReport::DECISION_RISKY) {
                $stats['risky']++;
            } else {
                $stats['wait']++;
            }
        }

        return $stats;
    }

    /**
     * @param AiSymbolReport[] $reports
     */
    private function latestGeneratedAt(array $reports): ?\DateTimeImmutable
    {
        $latest = null;

        foreach ($reports as $report) {
            if (!$report instanceof AiSymbolReport || $report->getCreatedAt() === null) {
                continue;
            }

            if ($latest === null || $report->getCreatedAt() > $latest) {
                $latest = $report->getCreatedAt();
            }
        }

        return $latest;
    }
}
