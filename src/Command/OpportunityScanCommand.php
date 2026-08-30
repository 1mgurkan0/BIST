<?php

namespace App\Command;

use App\Entity\OpportunityCandidate;
use App\Service\BistUniverseService;
use App\Service\OpportunityScoringService;
use App\Service\TechnicalAnalysisService;
use App\Service\YahooHistoryService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Lock\LockFactory;

#[AsCommand(
    name: 'app:opportunities:scan',
    description: 'Yapilandirilmis BIST evrenini teknik olarak tarar ve AI analizi icin adaylari siralar.',
)]
class OpportunityScanCommand extends Command
{
    public function __construct(
        private readonly BistUniverseService $universe,
        private readonly YahooHistoryService $historyService,
        private readonly TechnicalAnalysisService $technicalAnalysis,
        private readonly OpportunityScoringService $scoring,
        private readonly EntityManagerInterface $em,
        private readonly LockFactory $lockFactory,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Adaylari gosterir ama veritabanina yazmaz.')
            ->addOption('symbol', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Sadece verilen sembolleri tara.')
            ->addOption('delay', null, InputOption::VALUE_OPTIONAL, 'Geriye uyumluluk icin korunur; batch beklemesi otomatik yonetilir.', 0)
            ->addOption('show', null, InputOption::VALUE_OPTIONAL, 'Tabloda gosterilecek aday sayisi.', 10);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $lock = $this->lockFactory->createLock('opportunity_scan_process', 1800.0, false);
        if (!$lock->acquire()) {
            $io->error('Baska bir firsat taramasi halen calisiyor.');
            return Command::FAILURE;
        }

        try {
            return $this->runScan($input, $io);
        } finally {
            $lock->release();
        }
    }

    private function runScan(InputInterface $input, SymfonyStyle $io): int
    {
        $requested = $input->getOption('symbol');
        $symbols = is_array($requested) && $requested !== []
            ? $this->normalizeSymbols($requested)
            : $this->universe->symbols();
        $dryRun = (bool) $input->getOption('dry-run');
        $show = max(1, (int) $input->getOption('show'));
        $batchId = bin2hex(random_bytes(16));

        if ($symbols === []) {
            $io->error('Taranacak sembol evreni bos.');
            return Command::INVALID;
        }

        // Fetch XU100 for index trend filter
        $fetchSymbols = array_unique(array_merge($symbols, ['XU100']));
        $historyMap = $this->historyService->fetchBatch($fetchSymbols);
        
        $xu100History = $historyMap['XU100'] ?? [];
        $xu100Technical = $this->technicalAnalysis->analyze(is_array($xu100History['bars'] ?? null) ? $xu100History['bars'] : []);

        $candidates = [];
        foreach ($symbols as $symbol) {
            try {
                $history = $historyMap[$symbol] ?? [
                    'status' => 'missing_history',
                    'source' => 'none',
                    'isStale' => true,
                    'bars' => [],
                ];
                $technical = $this->technicalAnalysis->analyze(is_array($history['bars'] ?? null) ? $history['bars'] : []);
                $technical['xu100'] = $xu100Technical; // Inject XU100 data

                $result = $this->scoring->score($technical, $history);

                $candidate = (new OpportunityCandidate())
                    ->setBatchId($batchId)
                    ->setSymbol($symbol)
                    ->setScore($result['score'])
                    ->setStatus($result['status'])
                    ->setHistoryStatus((string) ($history['status'] ?? 'missing_history'))
                    ->setIsHistoryStale((bool) ($history['isStale'] ?? true))
                    ->setTechnicalSnapshot($technical)
                    ->setReasons($result['reasons']);
                $candidates[] = $candidate;
            } catch (\Throwable $e) {
                $this->logger->error(sprintf('Sembol tarama hatasi: %s - %s', $symbol, $e->getMessage()), ['exception' => $e]);
                continue;
            }
        }

        usort($candidates, fn(OpportunityCandidate $a, OpportunityCandidate $b): int =>
            ($a->getStatus() === OpportunityCandidate::STATUS_ELIGIBLE ? 0 : 1)
            <=> ($b->getStatus() === OpportunityCandidate::STATUS_ELIGIBLE ? 0 : 1)
            ?: $b->getScore() <=> $a->getScore()
            ?: $a->getSymbol() <=> $b->getSymbol()
        );

        $rank = 0;
        foreach ($candidates as $candidate) {
            if ($candidate->getStatus() === OpportunityCandidate::STATUS_ELIGIBLE) {
                $candidate->setRank(++$rank);
            }
        }

        $minimumEligible = min(count($symbols), max(1, (int) ceil(count($symbols) * 0.05)));
        if ($rank < $minimumEligible) {
            $io->error(sprintf(
                'Tarama kalite esigini gecemedi: %d/%d taze sembol (minimum %d). Son basarili tarama korunuyor.',
                $rank,
                count($symbols),
                $minimumEligible
            ));
            return Command::FAILURE;
        }

        if (!$dryRun) {
            foreach ($candidates as $candidate) {
                $this->em->persist($candidate);
            }
            $this->em->flush();
        }

        $io->table(
            ['Sira', 'Sembol', 'Skor', 'Veri', 'Neden'],
            array_map(fn(OpportunityCandidate $candidate): array => [
                $candidate->getRank() ?? '-',
                $candidate->getSymbol(),
                $candidate->getScore(),
                $candidate->getStatus() . '/' . $candidate->getHistoryStatus(),
                implode(' ', $candidate->getReasons()),
            ], array_slice($candidates, 0, $show))
        );

        $io->success(sprintf(
            '%d sembol tarandi, %d taze aday siralandi.%s',
            count($candidates),
            $rank,
            $dryRun ? ' Dry-run: sonuc kaydedilmedi.' : ''
        ));

        return $rank > 0 ? Command::SUCCESS : Command::FAILURE;
    }

    /** @return string[] */
    private function normalizeSymbols(array $symbols): array
    {
        $result = [];
        foreach ($symbols as $symbol) {
            $symbol = strtoupper(trim((string) $symbol));
            $symbol = str_ends_with($symbol, '.IS') ? substr($symbol, 0, -3) : $symbol;
            if (preg_match('/^[A-Z0-9]{2,20}$/', $symbol)) {
                $result[$symbol] = true;
            }
        }

        return array_keys($result);
    }
}
