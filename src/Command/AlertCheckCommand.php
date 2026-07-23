<?php

namespace App\Command;

use App\Entity\PriceAlert;
use App\Repository\PriceAlertRepository;
use App\Service\PriceSnapshotService;
use App\Service\TelegramService;
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
    name: 'app:alerts:check',
    description: 'Aktif fiyat alarmlarini kontrol eder ve tetiklenenleri dashboard icin isaretler.',
)]
class AlertCheckCommand extends Command
{
    public function __construct(
        private readonly PriceAlertRepository $alertRepository,
        private readonly PriceSnapshotService $priceSnapshot,
        private readonly TelegramService $telegramService,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
        private readonly LockFactory $lockFactory,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Alarmlari guncellemeden sonucu goster.')
            ->addOption('no-telegram', null, InputOption::VALUE_NONE, 'Tetiklenen alarmlarda Telegram mesaji gonderme.')
            ->addOption('symbol', null, InputOption::VALUE_REQUIRED, 'Sadece tek sembolun alarmlarini kontrol et.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $lock = $this->lockFactory->createLock('price_alert_check', 120.0, false);
        if (!$lock->acquire()) {
            (new SymfonyStyle($input, $output))->warning('Baska bir alarm kontrolu halen calisiyor.');
            return Command::SUCCESS;
        }

        try {
            return $this->runAlertCheck($input, $output);
        } finally {
            $lock->release();
        }
    }

    private function runAlertCheck(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        $sendTelegram = !$dryRun && !(bool) $input->getOption('no-telegram');
        $symbol = $input->getOption('symbol');
        $symbol = is_string($symbol) && trim($symbol) !== '' ? strtoupper(trim($symbol)) : null;

        if ($symbol !== null && !preg_match('/^[A-Z0-9]{2,20}$/', $symbol)) {
            $io->error('Gecerli bir sembol girin.');
            return Command::INVALID;
        }

        $alerts = $this->alertRepository->findActive($symbol);
        if (empty($alerts)) {
            $io->success($symbol ? $symbol . ' icin aktif alarm yok.' : 'Aktif fiyat alarmi yok.');
            return Command::SUCCESS;
        }

        $symbols = array_values(array_unique(array_map(
            fn(PriceAlert $alert): string => $alert->getSymbol(),
            $alerts
        )));
        $quoteMap = $this->priceSnapshot->itemsForSymbols($symbols);

        $rows = [];
        $triggered = 0;
        $telegramQueue = [];
        $telegramSent = 0;
        $telegramFailed = 0;

        foreach ($alerts as $alert) {
            $alertSymbol = $alert->getSymbol();
            $quoteStatus = $quoteMap[$alertSymbol] ?? null;
            $status = $quoteStatus['quoteStatus'] ?? ($quoteStatus['status'] ?? 'missing_price');
            $httpStatus = $quoteStatus['httpStatus'] ?? null;
            $isStale = (bool) ($quoteStatus['isStale'] ?? false);

            $result = 'Veri yok';
            $price = null;
            $changePercent = null;

            if (is_array($quoteStatus) && is_numeric($quoteStatus['price'] ?? null) && is_numeric($quoteStatus['dailyChangePercent'] ?? null)) {
                $price = (float) $quoteStatus['price'];
                $changePercent = (float) $quoteStatus['dailyChangePercent'];
                $shouldTrigger = $this->shouldTrigger($alert, $price, $changePercent);

                if ($status !== 'ok' || $isStale) {
                    $result = $shouldTrigger
                        ? 'Stale veri, tetiklenmedi'
                        : 'Stale veri, bekliyor';

                    if (!$dryRun) {
                        $alert->markChecked($price, $status, is_int($httpStatus) ? $httpStatus : null);
                    }
                } elseif ($shouldTrigger) {
                    $result = $dryRun ? 'Tetiklenecek' : ($sendTelegram ? 'Tetiklendi, Telegram bekliyor' : 'Tetiklendi');
                    $triggered++;

                    if (!$dryRun) {
                        $alert->markTriggered($price, $status, is_int($httpStatus) ? $httpStatus : null);
                        if ($sendTelegram) {
                            $telegramQueue[] = [
                                'alert' => $alert,
                                'price' => $price,
                                'changePercent' => $changePercent,
                                'quoteStatus' => $status,
                                'httpStatus' => is_int($httpStatus) ? $httpStatus : null,
                                'isStale' => $isStale,
                                'rowIndex' => count($rows),
                            ];
                        }
                    }
                } else {
                    $result = 'Bekliyor';
                    if (!$dryRun) {
                        $alert->markChecked($price, $status, is_int($httpStatus) ? $httpStatus : null);
                    }
                }
            } elseif (!$dryRun) {
                $alert->markChecked(null, $status, is_int($httpStatus) ? $httpStatus : null);
            }

            $rows[] = [
                $alertSymbol,
                $alert->conditionLabel(),
                $alert->targetLabel(),
                $price === null ? '-' : $this->formatPrice($price),
                $changePercent === null ? '-' : $this->formatPercent($changePercent),
                $this->formatQuoteStatus($status, is_int($httpStatus) ? $httpStatus : null),
                $result,
            ];
        }

        if (!$dryRun) {
            foreach ($telegramQueue as $telegramItem) {
                $telegramOk = $this->sendTelegramAlert(
                    $telegramItem['alert'],
                    $telegramItem['price'],
                    $telegramItem['changePercent'],
                    $telegramItem['quoteStatus'],
                    $telegramItem['httpStatus'],
                    $telegramItem['isStale']
                );

                if ($telegramOk) {
                    $telegramSent++;
                    $rows[$telegramItem['rowIndex']][6] = 'Tetiklendi, Telegram OK';
                } else {
                    $telegramFailed++;
                    $telegramItem['alert']
                        ->resetTrigger()
                        ->markChecked(
                            $telegramItem['price'],
                            $telegramItem['quoteStatus'],
                            $telegramItem['httpStatus']
                        );
                    $rows[$telegramItem['rowIndex']][6] = 'Telegram hata, alarm aktif';
                }
            }

            $this->em->flush();
        }

        $io->table(
            ['Sembol', 'Kosul', 'Hedef', 'Fiyat', 'Gunluk %', 'Veri', 'Sonuc'],
            $rows
        );

        $this->logger->info('Price alerts checked.', [
            'checked' => count($alerts),
            'triggered' => $triggered,
            'dry_run' => $dryRun,
            'telegram_enabled' => $sendTelegram,
            'telegram_sent' => $telegramSent,
            'telegram_failed' => $telegramFailed,
        ]);

        $telegramSummary = $sendTelegram
            ? sprintf(' Telegram: %d gonderildi, %d hata.', $telegramSent, $telegramFailed)
            : ' Telegram kapali.';

        $summaryMessage = sprintf(
            '%d alarm kontrol edildi. %d alarm %s.',
            count($alerts),
            $triggered,
            $dryRun ? 'tetiklenecek durumda' : 'tetiklendi'
        ) . $telegramSummary;

        if ($telegramFailed > 0) {
            $io->error($summaryMessage);
            return Command::FAILURE;
        }

        $io->success($summaryMessage);
        return Command::SUCCESS;
    }

    private function shouldTrigger(PriceAlert $alert, float $price, float $changePercent): bool
    {
        return match ($alert->getConditionType()) {
            PriceAlert::TYPE_PRICE_ABOVE => $price >= $alert->getTargetValue(),
            PriceAlert::TYPE_PRICE_BELOW => $price <= $alert->getTargetValue(),
            PriceAlert::TYPE_PERCENT_UP => $changePercent >= $alert->getTargetValue(),
            PriceAlert::TYPE_PERCENT_DOWN => $changePercent <= -$alert->getTargetValue(),
            default => false,
        };
    }

    private function formatPrice(float $price): string
    {
        return 'TL ' . number_format($price, 2, ',', '.');
    }

    private function formatPercent(float $percent): string
    {
        return ($percent >= 0 ? '+' : '') . number_format($percent, 2, ',', '.') . '%';
    }

    private function formatQuoteStatus(string $status, ?int $httpStatus): string
    {
        if ($httpStatus === 429) {
            return '!429';
        }

        return $status;
    }

    private function sendTelegramAlert(
        PriceAlert $alert,
        float $price,
        float $changePercent,
        string $quoteStatus,
        ?int $httpStatus,
        bool $isStale
    ): bool
    {
        $note = $alert->getNote();
        $noteLine = $note === null ? '' : "\nNot: " . $this->escapeTelegramHtml(mb_substr($note, 0, 500));
        $dataStatusLine = $this->telegramDataStatusLine($quoteStatus, $httpStatus, $isStale);

        $message = sprintf(
            "<b>BAM Fiyat Alarmi</b>\n\n<b>%s</b> alarmi tetiklendi.\nKosul: %s\nHedef: %s\nAnlik fiyat: %s\nGunluk degisim: %s%s%s\n\n<i>Yatirim tavsiyesi degildir.</i>",
            $this->escapeTelegramHtml($alert->getSymbol()),
            $this->escapeTelegramHtml($alert->conditionLabel()),
            $this->escapeTelegramHtml($alert->targetLabel()),
            $this->escapeTelegramHtml($this->formatPrice($price)),
            $this->escapeTelegramHtml($this->formatPercent($changePercent)),
            $dataStatusLine,
            $noteLine
        );

        return $this->telegramService->sendMessage($message, 'HTML');
    }

    private function telegramDataStatusLine(string $quoteStatus, ?int $httpStatus, bool $isStale): string
    {
        if ($httpStatus === 429) {
            return "\nVeri durumu: !429 - son cekilen fiyata gore";
        }

        if ($quoteStatus !== 'ok' || $isStale) {
            return "\nVeri durumu: " . $this->escapeTelegramHtml($this->formatQuoteStatus($quoteStatus, $httpStatus)) . ' - son cekilen fiyata gore';
        }

        return '';
    }

    private function escapeTelegramHtml(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
