<?php

namespace App\Command;

use App\Service\KapScraperService;


use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:kap-crawl',
    description: 'KAP üzerindeki son bildirimleri çeker, analiz eder ve kaydeder.',
)]
class KapCrawlerCommand extends Command
{
    public function __construct(
        private KapScraperService $scraper
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ($output->isVerbose()) {
            $io->title('🚀 KAP Haber Tarayıcısı Başlatılıyor...');
        }

        $sleepSeconds = rand(1, 20);

        if ($output->isVerbose()) {
            $io->note("Bot koruması için {$sleepSeconds} saniye bekleniyor...");
        }

        sleep($sleepSeconds);

        try {
            $this->scraper->fetchAndSaveLatest();

            if ($output->isVerbose()) {
                $io->success('KAP taraması başarıyla tamamlandı.');
            }

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $io->error('KAP Scraper hata aldı! (Detaylar Telegram/log kanalına iletildi)');

            if ($output->isVerbose()) {
                $io->note('Hata mesajı: ' . $e->getMessage());
            }

            return Command::FAILURE;
        }
    }
}