<?php

namespace App\Command;

use App\Repository\KapNewsRepository;
use App\Service\GeminiService;
use App\Service\TelegramService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:run-analysis',
    description: 'Bekleyen haberleri Gemini AI ile analiz eder.',
)]
class KapAnalyzerCommand extends Command
{
    private const DEFAULT_BATCH_SIZE = 5;
    private const DEFAULT_THRESHOLD = 65;
    private const RATE_LIMIT_DELAY = 2_000_000;
    private const MAX_RETRIES = 5;
    private const INITIAL_BACKOFF = 2_000_000;

    private const AI_PROMPT = <<<PROMPT
        Senin adın 'BorsaGemini'. 25 yıllık tecrüben var, hedge fonlarda çalıştın, krizleri gördün.
        Şu an bir yatırım danışmanısın ve müşterine haber analizi yapıyorsun.
        
        **GÖREV:**
        Aşağıdaki haberi oku ve bir borsa profesyoneli gözüyle analiz et.
        
        **ANALİZ YAPARKEN ŞUNLARI DEĞERLENDİR:**
        1. Haberin doğruluk/güvenilirlik seviyesi (kaynak, detay, teyit)
        2. Kısa vadeli etki (1-5 gün)
        3. Uzun vadeli etki (1-6 ay)
        4. Hangi sektörleri/hisseleri etkiler?
        5. Rakipler için ne anlama gelir?
        6. Makroekonomik etkileri var mı?
        7. Daha önce benzer haberlerde piyasa nasıl tepki verdi?
        
        **PUANLAMA SİSTEMİ:**
        - -100 ile +100 arası (uç değerler çok nadir)
        - -100: Şirket batıyor, iflas haberi
        - -50: Ciddi negatif, sat sinyali
        - 0: Nötr, piyasa zaten fiyatlamış
        - +50: Ciddi pozitif, al sinyali
        - +100: Tarihi fırsat, kesin al
        
        **CEVAP FORMATI (Kesinlikle bu formatta):**
        
        **ANALİZ RAPORU**
        
        **HABER ÖZETİ:** [1 cümle]
        
        **KISA VADE (1-5 gün):** [Tahmin + gerekçe]
        **UZUN VADE (1-6 ay):** [Tahmin + gerekçe]
        
        **ETKİ PUANI:** [Pozitif/Negatif/Nötr] | [Puan: -85 ile +85]
        
        **NEDEN BU PUAN:** [2-3 cümle açıklama]
        
        **ETKİLENEN SEKTÖRLER:** [Sektör listesi]
        **ETKİLENEN HİSSELER:** [Varsa doğrudan ilgili hisseler]
        
        **YATIRIMCI NE YAPMALI?**
        - **Kısa vade:** [Aksiyon önerisi]
        - **Uzun vade:** [Aksiyon önerisi]
        - **Riskler:** [Dikkat edilmesi gerekenler]
        
        **ALTERNATİF SENARYOLAR:**
        - İyimser senaryo: [Olasılık %]
        - Kötümser senaryo: [Olasılık %]
        - Beklenen senaryo: [Olasılık %]
        
        **UYARILAR:** [Varsa önemli notlar]
        
        **GÜVENİLİRLİK:** [Düşük/Orta/Yüksek] - [Nedeni]
PROMPT;

    public function __construct(
        private KapNewsRepository $repo,
        private GeminiService $gemini,
        private EntityManagerInterface $em,
        private TelegramService $telegram,
        private LoggerInterface $logger

    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Veritabanına kaydetmeden test et')
            ->addOption('limit', 'l', InputOption::VALUE_OPTIONAL, 'Analiz edilecek haber sayısı', self::DEFAULT_BATCH_SIZE)
            ->addOption('threshold', 't', InputOption::VALUE_OPTIONAL, 'Sinyal eşiği', self::DEFAULT_THRESHOLD)
            ->addOption('delay', 'd', InputOption::VALUE_OPTIONAL, 'İstekler arası bekleme saniye', 2);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = $input->getOption('dry-run');
        $limit = (int) $input->getOption('limit');
        $threshold = (int) $input->getOption('threshold');
        $delaySeconds = (float) $input->getOption('delay');

        $waitingNews = $this->repo->findBy(['isAnalyzed' => false], ['id' => 'DESC'], $limit);
        $count = count($waitingNews);

        if ($count === 0) {
            $io->success('Analiz edilecek yeni haber yok.');
            return Command::SUCCESS;
        }

        $io->text("$count adet haber analiz ediliyor...");
        $io->note("Rate limit koruması: Her haber arasında $delaySeconds saniye beklenecek");

        if ($dryRun) {
            $io->warning('DRY RUN modu: Veritabanına kayıt yapılmayacak');
        }

        $signalCount = 0;
        $successCount = 0;

        foreach ($waitingNews as $index => $news) {
            if ($index > 0) {
                $io->writeln("⏳ " . number_format($delaySeconds, 1) . " saniye bekleniyor...");
                usleep($delaySeconds * 1_000_000);
            }

            $attempt = 1;
            $success = false;

            while (!$success && $attempt <= self::MAX_RETRIES) {
                try {
                    $symbols = empty($news->getStockCodes()) ? 'GENEL' : implode(', ', $news->getStockCodes());

                    $newsContent = "HİSSE: $symbols \n" . $news->getContent();
                    $prompt = self::AI_PROMPT . "\n\nHABER:\n" . $newsContent;


                    $analizSonucu = $this->gemini->ask(
                         $prompt
                    );

                    $puan = $this->extractScore($analizSonucu);
                    $durum = $this->extractStatus($analizSonucu);
                    $ozet = $this->extractSummary($analizSonucu);
                    $gerekce = $this->extractReason($analizSonucu);
                    $etki = $this->extractImpact($analizSonucu);
                    $sektor = $this->extractSector($analizSonucu);
                    $hisseler = $this->extractStocks($analizSonucu);
                    $uyari = $this->extractWarning($analizSonucu);

                    if (!$dryRun) {
                        $news->setAiSummary($analizSonucu);
                        $news->setSentimentScore($puan);
                        $news->setIsAnalyzed(true);
                        $news->setAnalyzedAt(new \DateTimeImmutable());
                        $this->em->persist($news);
                    }

                    $io->writeln("✅ " . $news->getKapId() . " analiz edildi. Durum: $durum, Puan: $puan");

                    if (abs($puan) >= $threshold) {
                        $this->sendSignalToTelegram(
                            symbols: $symbols,
                            puan: $puan,
                            durum: $durum,
                            ozet: $ozet,
                            gerekce: $gerekce,
                            etki: $etki,
                            sektor: $sektor,
                            hisseler: $hisseler,
                            uyari: $uyari,
                            kapId: $news->getKapId()
                        );
                        $signalCount++;
                        $io->writeln("   🚀 Sinyal gönderildi!");
                    }

                    $success = true;
                    $successCount++;

                } catch (\Exception $e) {
                    if (str_contains($e->getMessage(), '429')) {
                        if ($attempt < self::MAX_RETRIES) {
                            $backoffTime = self::INITIAL_BACKOFF * pow(2, $attempt - 1);
                            $backoffSeconds = $backoffTime / 1_000_000;

                            $io->warning("429 Rate limit aşıldı! {$backoffSeconds} saniye bekleniyor... (Deneme $attempt/" . self::MAX_RETRIES . ")");
                            $this->logger->warning('Rate limit aşıldı, backoff uygulanıyor', [
                                'news_id' => $news->getId(),
                                'attempt' => $attempt,
                                'backoff' => $backoffSeconds
                            ]);

                            usleep($backoffTime);
                            $attempt++;
                            continue;
                        }
                    }

                    $io->error("Hata (ID: {$news->getKapId()}): " . $e->getMessage());

                    $this->logger->error('Analiz hatası', [
                        'news_id' => $news->getId(),
                        'kap_id' => $news->getKapId(),
                        'error' => $e->getMessage(),
                        'attempt' => $attempt
                    ]);

                    break;
                }
            }

            if (($index + 1) % 2 == 0 && $index < $count - 1) {
                $io->writeln("⏸️ Mola: 3 saniye bekleniyor...");
                usleep(3_000_000);
            }
        }

        if (!$dryRun && $successCount > 0) {
            $this->em->flush();
        }

        $io->success("Analiz tamamlandı. $count haber işlendi, $successCount başarılı, $signalCount sinyal gönderildi.");

        return Command::SUCCESS;
    }

    private function extractScore(string $analiz): int
    {
        if (preg_match('/PUAN:\s*([+-]?\d+)/i', $analiz, $matches)) {
            return (int) $matches[1];
        }
        return 0;
    }

    private function extractStatus(string $analiz): string
    {
        if (preg_match('/DURUM:\s*([PpNn][öa-zşçğüı]+)/u', $analiz, $matches)) {
            return trim($matches[1]);
        }
        return 'Nötr';
    }

    private function extractSummary(string $analiz): string
    {
        if (preg_match('/OZET:\s*(.+?)(?=\n|$)/u', $analiz, $matches)) {
            return trim($matches[1]);
        }
        return 'Özet bulunamadı.';
    }

    private function extractReason(string $analiz): string
    {
        if (preg_match('/GEREKCE:\s*(.+?)(?=\n|$)/u', $analiz, $matches)) {
            return trim($matches[1]);
        }
        return '';
    }

    private function extractImpact(string $analiz): string
    {
        if (preg_match('/ETKI:\s*(.+?)(?=\n|$)/u', $analiz, $matches)) {
            return trim($matches[1]);
        }
        return '';
    }

    private function extractSector(string $analiz): string
    {
        if (preg_match('/SEKTOR:\s*(.+?)(?=\n|$)/u', $analiz, $matches)) {
            return trim($matches[1]);
        }
        return '';
    }

    private function extractStocks(string $analiz): string
    {
        if (preg_match('/HISSELER:\s*(.+?)(?=\n|$)/u', $analiz, $matches)) {
            return trim($matches[1]);
        }
        return '';
    }

    private function extractWarning(string $analiz): string
    {
        if (preg_match('/UYARI:\s*(.+?)(?=\n|$)/u', $analiz, $matches)) {
            return trim($matches[1]);
        }
        return '';
    }

    private function sendSignalToTelegram(
        string $symbols,
        int $puan,
        string $durum,
        string $ozet,
        string $gerekce,
        string $etki,
        string $sektor,
        string $hisseler,
        string $uyari,
        string $kapId
    ): void {
        $emoji = match(true) {
            $puan >= 80 => '🚀🔥',
            $puan >= 50 => '📈',
            $puan <= -80 => '💀🔻',
            $puan <= -50 => '📉',
            default => '🟡'
        };

        $msg = "$emoji *KRİTİK ANALİZ*\n\n";
        $msg .= "*📊 Hisse:* $symbols\n";
        $msg .= "*🎯 Puan:* $puan/100\n";
        $msg .= "*📌 Durum:* $durum\n";
        $msg .= "*📰 Özet:* $ozet\n";

        if ($gerekce) {
            $msg .= "*💡 Gerekçe:* $gerekce\n";
        }

        if ($etki) {
            $msg .= "*⚡ Etki:* $etki\n";
        }

        if ($sektor) {
            $msg .= "*🏭 Sektör:* $sektor\n";
        }

        if ($hisseler && $hisseler !== $symbols) {
            $msg .= "*📈 İlgili Hisseler:* $hisseler\n";
        }

        if ($uyari) {
            $msg .= "*⚠️ Uyarı:* $uyari\n";
        }

        $msg .= "\n🔗 [Haberi Oku](https://www.kap.org.tr/tr/Bildirim/$kapId)";

        try {
            $this->telegram->sendMessage($msg, 'Markdown');
            $this->logger->info('Telegram sinyali gönderildi', [
                'symbols' => $symbols,
                'puan' => $puan
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Telegram gönderilemedi', [
                'error' => $e->getMessage()
            ]);
        }
    }
}