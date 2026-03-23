<?php

declare(strict_types=1);

namespace App\Command;

use App\DTO\MarketDataDto;
use App\Service\YahooFinanceService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

#[AsCommand(
    name: 'app:fetch-bist30',
    description: 'BIST 30 fiyatlarını Yahoo Finance\'ten çeker ve cache\'e yazar.',
)]
class StockFetchCommand extends Command
{
    public const CACHE_KEY = 'bist30.live.data';
    public const CACHE_TTL = 180;

    private const SYMBOLS = [
        'AKBNK', 'ARCLK', 'ASELS', 'BIMAS', 'DOHOL',
        'EKGYO', 'EREGL', 'FROTO', 'GARAN', 'GUBRF',
        'HEKTS', 'ISCTR', 'KCHOL', 'KOZAA', 'KOZAL',
        'KRDMD', 'MGROS', 'ODAS',  'PETKM', 'PGSUS',
        'SAHOL', 'SASA',  'SISE',  'SKBNK', 'TAVHL',
        'TCELL', 'THYAO', 'TKFEN', 'TOASO', 'TUPRS',
    ];

    public function __construct(
        private readonly YahooFinanceService $yahooFinance,
        private readonly CacheInterface      $cache,
        private readonly LoggerInterface     $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'dry-run',
            null,
            InputOption::VALUE_NONE,
            'Sadece veriyi göster, cache\'e yazma.'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io     = new SymfonyStyle($input, $output);
        $dryRun = $input->getOption('dry-run');
        $start  = microtime(true);

        $io->title('BAM — BIST 30 Veri Çekici');
        $io->text(sprintf('[%s] %d sembol çekiliyor...', date('H:i:s'), count(self::SYMBOLS)));

        try {
            $marketData = $this->yahooFinance->fetchBatch(self::SYMBOLS);
        } catch (\Throwable $e) {
            $io->error('Veri çekilirken kritik hata: ' . $e->getMessage());
            $this->logger->critical('BIST30 fetch başarısız.', ['exception' => $e->getMessage()]);
            return Command::FAILURE;
        }

        if (empty($marketData)) {
            $io->warning('API\'den hiç veri dönmedi.');
            $this->logger->warning('BIST30 fetch: boş yanıt.');
            return Command::FAILURE;
        }

        $fetched = count($marketData);
        $missing = count(self::SYMBOLS) - $fetched;

        $rows = [];
        foreach (self::SYMBOLS as $sym) {
            if (!isset($marketData[$sym])) {
                $rows[] = [$sym, '-', '-', '-', '-'];
                continue;
            }
            $dto    = $marketData[$sym];
            $change = $dto->changePercent();
            $rows[] = [
                $dto->symbol,
                number_format($dto->price, 2) . ' TL',
                ($change >= 0 ? '+' : '') . number_format($change, 2) . '%',
                number_format($dto->low, 2) . ' – ' . number_format($dto->high, 2),
                number_format($dto->volume),
            ];
        }

        $io->table(['Sembol', 'Fiyat', 'Degisim', 'Gun Ici Aralik', 'Hacim'], $rows);

        if (!$dryRun) {
            $payload = [
                'fetchedAt' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                'items'     => array_map(fn(MarketDataDto $d) => [
                    'symbol'        => $d->symbol,
                    'price'         => $d->price,
                    'open'          => $d->open,
                    'high'          => $d->high,
                    'low'           => $d->low,
                    'previousClose' => $d->previousClose,
                    'volume'        => $d->volume,
                    'changePercent' => $d->changePercent(),
                ], $marketData),
            ];

            $this->cache->delete(self::CACHE_KEY);
            $this->cache->get(self::CACHE_KEY, function (ItemInterface $item) use ($payload): array {
                $item->expiresAfter(self::CACHE_TTL);
                return $payload;
            });

            $this->logger->info('BIST30 cache guncellendi.', [
                'fetched' => $fetched,
                'missing' => $missing,
            ]);

            $io->success(sprintf(
                '%d sembol cacehe yazildi. %d sembol eksik. Sure: %.2f sn.',
                $fetched,
                $missing,
                microtime(true) - $start
            ));
        } else {
            $io->note('Dry-run modu: cacehe yazilmadi.');
        }

        return Command::SUCCESS;
    }
}