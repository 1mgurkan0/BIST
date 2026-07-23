<?php

namespace App\Command;

use App\Service\KapScraperService;
use App\Service\PriceSnapshotService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Lock\LockFactory;

#[AsCommand(
    name: 'app:kap-crawl',
    description: 'KAP bildirimlerini resmi JSON akisi uzerinden ceker ve kaydeder.',
)]
class KapCrawlerCommand extends Command
{
    public function __construct(
        private readonly KapScraperService $scraper,
        private readonly PriceSnapshotService $priceSnapshot,
        private readonly LockFactory $lockFactory,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('days', null, InputOption::VALUE_OPTIONAL, 'Geriye bakilacak gun sayisi (1-30).', 2)
            ->addOption('all-bist', null, InputOption::VALUE_NONE, 'Takip listesi yerine tum BIST sirket bildirimlerini kaydet.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'KAP cevabini kontrol et ama veritabanina yazma.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $lock = $this->lockFactory->createLock('kap_crawler', 600.0, false);
        if (!$lock->acquire()) {
            (new SymfonyStyle($input, $output))->warning('Baska bir KAP taramasi halen calisiyor.');
            return Command::SUCCESS;
        }

        try {
            return $this->runCrawler($input, $output);
        } finally {
            $lock->release();
        }
    }

    private function runCrawler(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $days = max(1, min(30, (int) $input->getOption('days')));
        $dryRun = (bool) $input->getOption('dry-run');
        $symbols = (bool) $input->getOption('all-bist') ? null : $this->priceSnapshot->trackedSymbols();

        if ($symbols !== null && empty($symbols)) {
            $io->success('KAP haberi izlenecek portfolio/takip sembolu yok.');
            return Command::SUCCESS;
        }

        try {
            $summary = $this->scraper->fetchAndSaveLatest($symbols, $days, $dryRun);
        } catch (\Throwable $e) {
            $io->error('KAP taramasi basarisiz: ' . $e->getMessage());
            return Command::FAILURE;
        }

        $io->table(
            ['KAP cevabi', 'Eslesen', 'Yeni', 'Mevcut', 'Atlanan', 'Mod'],
            [[
                $summary['received'],
                $summary['matched'],
                $summary['created'],
                $summary['existing'],
                $summary['skipped'],
                $dryRun ? 'dry-run' : 'kaydedildi',
            ]]
        );
        $io->success('KAP taramasi tamamlandi.');

        return Command::SUCCESS;
    }
}
