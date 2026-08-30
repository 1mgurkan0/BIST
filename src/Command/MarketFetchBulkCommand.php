<?php

namespace App\Command;

use App\Service\BistUniverseService;
use App\Service\MarketCacheService;
use App\Service\YahooHistoryService;
use App\Service\TechnicalAnalysisService;
use App\Service\OpportunityScoringService;
use App\Repository\OpportunityCandidateRepository;
use App\Service\TradingViewMarketService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Lock\LockFactory;

#[AsCommand(
    name: 'app:market:fetch-bulk',
    description: 'TradingView uzerinden 116 hisseyi ceker, teknik analizi gunceller ve cache e yazar.',
)]
class MarketFetchBulkCommand extends Command
{
    public function __construct(
        private readonly BistUniverseService $universeService,
        private readonly TradingViewMarketService $tradingViewService,
        private readonly LockFactory $lockFactory,
        private readonly MarketCacheService $cacheService,
        private readonly YahooHistoryService $historyService,
        private readonly TechnicalAnalysisService $technicalAnalysis,
        private readonly OpportunityScoringService $scoringService,
        private readonly OpportunityCandidateRepository $candidateRepository,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $lock = $this->lockFactory->createLock('market_fetch_bulk', 120.0, false);
        if (!$lock->acquire()) {
            (new SymfonyStyle($input, $output))->warning('Baska bir toplu cekim halen calisiyor.');
            return Command::SUCCESS;
        }

        try {
            return $this->runFetch($input, $output);
        } finally {
            $lock->release();
        }
    }

    private function runFetch(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $symbols = $this->universeService->symbols();

        if (empty($symbols)) {
            $io->warning('Bist evreni bos!');
            return Command::FAILURE;
        }

        $results = $this->tradingViewService->fetchBulkPrices($symbols);

        if (empty($results)) {
            $io->error('Veri cekilemedi veya donus bos.');
            return Command::FAILURE;
        }

        $historyMap = $this->historyService->fetchBatch($symbols);
        file_put_contents('var/debug_history.json', json_encode($historyMap['THYAO'] ?? ['error' => 'Not found']));
        
        $aiCacheFile = 'var/ai_log_cache.json';
        if (file_exists($aiCacheFile) && time() - filemtime($aiCacheFile) < 3600) {
            $aiLogs = json_decode(file_get_contents($aiCacheFile), true) ?? [];
        } else {
            $latestOpportunities = $this->candidateRepository->findBy([], ['scanDate' => 'DESC'], 500);
            $aiLogs = [];
            foreach ($latestOpportunities as $opp) {
                if (!isset($aiLogs[$opp->getSymbol()])) {
                    $aiLogs[$opp->getSymbol()] = [
                        'score' => $opp->getScore(),
                        'status' => $opp->getStatus(),
                        'reasons' => $opp->getReasons(),
                        'scanDate' => $opp->getScanDate()->format('c'),
                    ];
                }
            }
            @file_put_contents($aiCacheFile, json_encode($aiLogs));
        }

        // XU100 verisi (Dongu disinda tek seferlik)
        try {
            $xu100History = $this->historyService->fetchBatch(['XU100'])['XU100'] ?? ['bars' => []];
            $xu100Technical = $this->technicalAnalysis->analyze($xu100History['bars'] ?? []);
        } catch (\Throwable $e) {
            $xu100Technical = ['status' => 'insufficient_history'];
        }

        $oldCache = $this->cacheService->read()['data'] ?? [];
        $cacheData = $oldCache; 
        
        $count = 0;

        foreach ($results as $symbol => $data) {
            try {
                $previousClose = $data['price'] / (1 + ($data['change'] / 100));
                $bars = $historyMap[$symbol]['bars'] ?? [];
                
                if (!empty($bars)) {
                    $lastKnown = end($bars);
                    $bars[] = [
                        'close'  => $data['price'],
                        'open'   => $lastKnown['close'] ?? $data['price'],
                        'high'   => max($lastKnown['close'] ?? $data['price'], $data['price']),
                        'low'    => min($lastKnown['close'] ?? $data['price'], $data['price']),
                        'volume' => $lastKnown['volume'] ?? 0,
                    ];
                }
                
                $technicalData = $this->technicalAnalysis->analyze($bars);
                $technicalData['xu100'] = $xu100Technical;
                
                $history = $historyMap[$symbol] ?? ['bars' => [], 'status' => 'stale'];
                $bamScoreData = $this->scoringService->score($technicalData, $history);
                
                $cacheData[$symbol] = [
                    'symbol' => $symbol,
                    'price' => $data['price'],
                    'change_percent' => $data['change'],
                    'previous_close' => $previousClose,
                    'rsi' => $technicalData['rsi14'] ?? null,
                    'macd_signal' => $technicalData['macd']['signal'] ?? null,
                    'macd_hist' => $technicalData['macd']['histogram'] ?? null,
                    'sma50' => $technicalData['sma50'] ?? null,
                    'sma200' => $technicalData['sma200'] ?? null,
                    'bam_score' => $bamScoreData['score'] ?? 0,
                    'ai_log' => $aiLogs[$symbol] ?? null,
                    'updated_at' => time(),
                ];

                $count++;
            } catch (\Throwable $e) {
                $io->warning(sprintf('%s icin islem sirasinda hata: %s', $symbol, $e->getMessage()));
                continue;
            }
        }
        
        $this->cacheService->writeAtomic($cacheData);
        $io->success(sprintf('%d adet sembol basariyla JSON cache guncellendi.', $count));

        return Command::SUCCESS;
    }
}

