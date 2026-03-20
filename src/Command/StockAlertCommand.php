<?php

declare(strict_types=1);

namespace App\Command;

use App\DTO\MarketDataDto;
use App\Service\TelegramService;
use App\Service\YahooFinanceService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:stock-alert',
    description: 'BIST 30 fiyatlarını çeker, sadece belirli hisseler sınır dışına çıkarsa Telegram\'a alarm gönderir.',
)]
class StockAlertCommand extends Command
{
    private const SYMBOLS = [
        'AKBNK', 'ARCLK', 'ASELS', 'BIMAS', 'DOHOL',
        'EKGYO', 'EREGL', 'FROTO', 'GARAN', 'GUBRF',
        'HEKTS', 'ISCTR', 'KCHOL', 'KOZAA', 'KOZAL',
        'KRDMD', 'MGROS', 'ODAS',  'PETKM', 'PGSUS',
        'SAHOL', 'SASA',  'SISE',  'SKBNK', 'TAVHL',
        'TCELL', 'THYAO', 'TKFEN', 'TOASO', 'TUPRS',
    ];

    private const ALERT_TARGETS = [
        'THYAO' => ['low' => 280.0, 'max' => 320.0],
        'ASELS' => ['low' => 330.0, 'max' => 355.0],
    ];

    public function __construct(
        private readonly YahooFinanceService $yahooFinanceService,
        private readonly TelegramService     $telegramService,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('📊 BAM — BIST 30 Fiyat Raporu');

        $io->text(sprintf('%d sembol çekiliyor...', count(self::SYMBOLS)));

        try {
            $marketData = $this->yahooFinanceService->fetchBatch(self::SYMBOLS);
        } catch (\Throwable $e) {
            $io->error('Veri çekilirken kritik hata: ' . $e->getMessage());
            return Command::FAILURE;
        }

        if (empty($marketData)) {
            $io->warning('API\'den hiç veri dönmedi.');
            return Command::FAILURE;
        }

        $io->success(sprintf('%d sembol başarıyla alındı.', count($marketData)));

        $rows = [];
        foreach ($marketData as $dto) {
            $rows[] = [
                $dto->symbol,
                number_format($dto->price, 2) . ' TL',
                sprintf('%+.2f%%', $dto->changePercent()),
                number_format($dto->low, 2) . ' – ' . number_format($dto->high, 2),
                number_format($dto->volume),
            ];
        }

        $io->table(
            ['Sembol', 'Fiyat', 'Değişim', 'Gün İçi Aralık', 'Hacim'],
            $rows,
        );

        $alertMessage = $this->checkAndBuildAlertMessage($marketData);

        if ($alertMessage !== null) {
            $io->warning('Kritik seviye tespit edildi! Telegram\'a gönderiliyor...');
            try {
                $this->telegramService->sendMessage($alertMessage);
                $io->success('✔️ Telegram alarmı gönderildi!');
            } catch (\Throwable $e) {
                $io->error('Telegram gönderilemedi: ' . $e->getMessage());
                return Command::FAILURE;
            }
        } else {
            $io->text('✅ Hedef hisseler (ASELS, THYAO) güvenli aralıkta. Telegram\'a mesaj atılmadı.');
        }

        return Command::SUCCESS;
    }

    /**
     * Sadece hedef hisseler sınır dışındaysa mesaj oluşturur. Yoksa null döner.
     * @param array<string, MarketDataDto> $marketData
     */
    private function checkAndBuildAlertMessage(array $marketData): ?string
    {
        $alertLines = [];

        foreach (self::ALERT_TARGETS as $symbol => $limits) {
            if (!isset($marketData[$symbol])) {
                continue;
            }

            $dto = $marketData[$symbol];
            $price = $dto->price;

            if ($price <= $limits['low']) {
                $alertLines[] = sprintf('📉 *%s DÜŞÜŞ ALARMI!* Fiyat: `%.2f TL` (Alt Sınır: %.2f)', $symbol, $price, $limits['low']);
            }
            elseif ($price >= $limits['max']) {
                $alertLines[] = sprintf('🚀 *%s YÜKSELİŞ ALARMI!* Fiyat: `%.2f TL` (Üst Sınır: %.2f)', $symbol, $price, $limits['max']);
            }
        }

        if (empty($alertLines)) {
            return null;
        }

        $finalMessage = [
            '🚨 *BAM HEDEF FİYAT ALARMI* 🚨',
            sprintf('🕐 _%s_', (new \DateTimeImmutable())->format('d.m.Y H:i:s')),
            str_repeat('─', 28),
            '',
        ];

        $finalMessage = array_merge($finalMessage, $alertLines);

        return implode("\n", $finalMessage);
    }
}