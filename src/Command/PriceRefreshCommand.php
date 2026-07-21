<?php

namespace App\Command;

use App\Service\PriceSnapshotService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:prices:refresh',
    description: 'Portfolio, takip listesi ve aktif alarm sembollerinin fiyat snapshotini yeniler.',
)]
class PriceRefreshCommand extends Command
{
    public function __construct(
        private readonly PriceSnapshotService $priceSnapshot,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Yahoo verisini gosterir ama snapshot cache yazmaz.')
            ->addOption('symbol', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Sadece verilen sembolleri yenile.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        $symbols = $input->getOption('symbol');
        $symbols = is_array($symbols) && !empty($symbols) ? $symbols : null;

        $payload = $this->priceSnapshot->refresh($symbols, $dryRun);
        $items = is_array($payload['items'] ?? null) ? $payload['items'] : [];
        $summary = is_array($payload['summary'] ?? null) ? $payload['summary'] : [];

        if (empty($items)) {
            $io->success($payload['message'] ?? 'Yenilenecek sembol yok.');
            return Command::SUCCESS;
        }

        $rows = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $rows[] = [
                $item['symbol'] ?? '-',
                $this->formatPrice($item['price'] ?? null),
                $this->formatPercent($item['dailyChangePercent'] ?? null),
                $this->formatStatus((string) ($item['quoteStatus'] ?? 'missing_price'), $item['httpStatus'] ?? null, (bool) ($item['isStale'] ?? false)),
                $item['source'] ?? '-',
            ];
        }

        $io->table(['Sembol', 'Fiyat', 'Gunluk %', 'Veri', 'Kaynak'], $rows);
        $io->success(sprintf(
            '%d sembol kontrol edildi. Taze: %d, stale: %d, eksik: %d, 429: %d.%s',
            (int) ($summary['total'] ?? count($rows)),
            (int) ($summary['fresh'] ?? 0),
            (int) ($summary['stale'] ?? 0),
            (int) ($summary['missing'] ?? 0),
            (int) ($summary['rateLimited'] ?? 0),
            $dryRun ? ' Dry-run: snapshot yazilmadi.' : ' Snapshot guncellendi.'
        ));

        return Command::SUCCESS;
    }

    private function formatPrice(mixed $price): string
    {
        return is_numeric($price) ? 'TL ' . number_format((float) $price, 2, ',', '.') : '-';
    }

    private function formatPercent(mixed $percent): string
    {
        return is_numeric($percent) ? (((float) $percent >= 0 ? '+' : '') . number_format((float) $percent, 2, ',', '.') . '%') : '-';
    }

    private function formatStatus(string $status, mixed $httpStatus, bool $isStale): string
    {
        if ((int) $httpStatus === 429) {
            return '!429';
        }

        return $isStale ? $status . ' / stale' : $status;
    }
}
