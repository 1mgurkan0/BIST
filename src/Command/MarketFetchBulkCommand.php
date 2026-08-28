<?php

namespace App\Command;

use App\Entity\Stock;
use App\Repository\StockRepository;
use App\Service\BistUniverseService;
use App\Service\TradingViewMarketService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Lock\LockFactory;

#[AsCommand(
    name: 'app:market:fetch-bulk',
    description: 'TradingView uzerinden 116 hisseyi tek seferde ceker ve veritabanina gunceller.',
)]
class MarketFetchBulkCommand extends Command
{
    public function __construct(
        private readonly BistUniverseService $universeService,
        private readonly TradingViewMarketService $tradingViewService,
        private readonly EntityManagerInterface $em,
        private readonly StockRepository $stockRepository,
        private readonly LockFactory $lockFactory,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $lock = $this->lockFactory->createLock('market_fetch_bulk', 50.0, false);
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

        $io->info(sprintf('%d sembol TradingView\'den toplu cekiliyor...', count($symbols)));
        
        $results = $this->tradingViewService->fetchBulkPrices($symbols);

        if (empty($results)) {
            $io->error('Veri cekilemedi veya donus bos.');
            return Command::FAILURE;
        }

        $count = 0;
        $now = new \DateTime();

        foreach ($results as $symbol => $data) {
            $existingStock = clone ($this->stockRepository->findLatest($symbol) ?? clone (new Stock()));
            
            $newStock = new Stock();
            $newStock->setSymbol($symbol);
            $newStock->setPrice($data['price']);
            $newStock->setPreviousClose($data['price'] / (1 + ($data['change'] / 100)));
            $newStock->setCreatedAt($now);
            
            // Handle uninitialized properties from cloned empty Stock safely
            try {
                $open = $existingStock->getOpen();
            } catch (\Error) {
                $open = null;
            }
            
            try {
                $high = $existingStock->getHigh();
            } catch (\Error) {
                $high = null;
            }
            
            try {
                $low = $existingStock->getLow();
            } catch (\Error) {
                $low = null;
            }

            try {
                $vol = $existingStock->getVolume();
            } catch (\Error) {
                $vol = null;
            }

            $newStock->setOpen($open ?? $data['price']);
            $newStock->setHigh($high ?? $data['price']);
            $newStock->setLow($low ?? $data['price']);
            $newStock->setVolume($vol ?? 0);

            $this->em->persist($newStock);
            $count++;
        }

        $this->em->flush();
        $io->success(sprintf('%d adet sembol basariyla veritabanina kaydedildi.', $count));

        return Command::SUCCESS;
    }
}
