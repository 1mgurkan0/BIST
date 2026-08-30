<?php

namespace App\Command;

use App\Service\BistUniverseService;
use App\Service\MarketCacheService;
use App\Service\TradingViewMarketService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Lock\LockFactory;

#[AsCommand(name: 'app:market:fetch-fundamentals', description: 'Gunluk sirket kunyesi verisi ceker.')]
class MarketFetchFundamentalsCommand extends Command
{
    public function __construct(
        private readonly BistUniverseService $universeService,
        private readonly TradingViewMarketService $tradingViewService,
        private readonly LockFactory $lockFactory,
        private readonly MarketCacheService $cacheService,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $lock = $this->lockFactory->createLock('market_fetch_fundamentals', 300.0, false);
        if (!$lock->acquire()) {
            return Command::SUCCESS;
        }

        try {
            $io = new SymfonyStyle($input, $output);
            $symbols = $this->universeService->symbols();
            
            $io->info('Sirket kunyesi bilgileri TradingView uzerinden cekiliyor...');
            $fresh = $this->tradingViewService->fetchFundamentals($symbols);
            
            if (empty($fresh)) {
                $io->warning('Yeni kunye verisi alinamadi, eski veri korunacak.');
                return Command::FAILURE;
            }

            $old = $this->cacheService->readFrom('fundamentals_cache.json')['data'] ?? [];
            $merged = array_replace($old, $fresh);
            
            $this->cacheService->writeAtomicTo('fundamentals_cache.json', $merged);
            $io->success(sprintf('%d sembolun kunyesi guncellendi.', count($merged)));
            
            return Command::SUCCESS;
        } finally {
            $lock->release();
        }
    }
}
