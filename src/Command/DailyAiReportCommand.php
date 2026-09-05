<?php

namespace App\Command;

use App\Entity\AiSymbolReport;
use App\Entity\AiSymbolHistory;
use App\Entity\KapNews;
use App\Entity\Portfolio;
use App\Entity\WatchlistItem;
use App\Interface\AiProviderInterface;
use App\Repository\KapNewsRepository;
use App\Repository\OpportunityCandidateRepository;
use App\Repository\PortfolioRepository;
use App\Repository\WatchlistItemRepository;
use App\Repository\AiSymbolHistoryRepository;
use App\Service\PriceSnapshotService;
use App\Service\TelegramService;
use App\Service\TechnicalAnalysisService;
use App\Service\YahooHistoryService;
use App\Service\OpportunityScoringService;
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
    name: 'app:daily-ai-report',
    description: 'Takip ve portfoy sembolleri icin fiyat + KAP destekli gun sonu AI raporu uretir.',
)]
class DailyAiReportCommand extends Command
{
    private const DEFAULT_DAYS = 7;
    private const DEFAULT_NEWS_LIMIT = 5;
    private const DEFAULT_DELAY = 12.0;

    public function __construct(
        private readonly PriceSnapshotService $priceSnapshot,
        private readonly YahooHistoryService $historyService,
        private readonly TechnicalAnalysisService $technicalAnalysis,
        private readonly PortfolioRepository $portfolioRepository,
        private readonly WatchlistItemRepository $watchlistRepository,
        private readonly OpportunityCandidateRepository $opportunityRepository,
        private readonly \App\Service\BistUniverseService $bistUniverse,
        private readonly KapNewsRepository $kapNewsRepository,
        private readonly AiProviderInterface $aiProvider,
        private readonly TelegramService $telegram,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
        private readonly LockFactory $lockFactory,
        private readonly OpportunityScoringService $scoring,
        private readonly AiSymbolHistoryRepository $aiSymbolHistoryRepository
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Raporu gosterir ama veritabanina yazmaz.')
            ->addOption('no-telegram', null, InputOption::VALUE_NONE, 'Telegram gun sonu ozetini gonderme.')
            ->addOption('mock-ai', null, InputOption::VALUE_NONE, 'Gemini cagirmadan deterministik test raporu uret.')
            ->addOption('skip-price-refresh', null, InputOption::VALUE_NONE, 'Mevcut fiyat snapshotini kullan, Yahoo fiyat yenilemesi yapma.')
            ->addOption('symbol', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Sadece verilen sembolleri analiz et.')
            ->addOption('opportunities', null, InputOption::VALUE_NONE, 'Son firsat taramasindaki en guclu adaylari analiz et.')
            ->addOption('opportunity-limit', null, InputOption::VALUE_OPTIONAL, 'AI analizi yapilacak firsat adayi sayisi.', 10)
            ->addOption('days', null, InputOption::VALUE_OPTIONAL, 'KAP haberleri icin geriye bakilacak gun sayisi.', self::DEFAULT_DAYS)
            ->addOption('news-limit', null, InputOption::VALUE_OPTIONAL, 'Sembol basina prompta alinacak KAP haberi sayisi.', self::DEFAULT_NEWS_LIMIT)
            ->addOption('delay', null, InputOption::VALUE_OPTIONAL, 'Gemini istekleri arasi bekleme saniyesi.', self::DEFAULT_DELAY);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $symbols = $this->resolveSymbols(
            $input->getOption('symbol'),
            (bool) $input->getOption('opportunities'),
            max(1, (int) $input->getOption('opportunity-limit'))
        );
        $modeKey = $input->getOption('opportunities') ? 'opportunity' : 'portfolio';
        $lock = $this->lockFactory->createLock('daily_ai_report_' . $modeKey, 3600.0, false);
        if (!$lock->acquire()) {
            (new SymfonyStyle($input, $output))->error('Baska bir gun sonu AI raporu halen calisiyor.');
            return Command::FAILURE;
        }

        try {
            return $this->runReport($input, $output);
        } finally {
            $lock->release();
        }
    }

    private function resolveSymbols(mixed $requestedSymbols, bool $opportunityMode, int $opportunityLimit): array
    {
        if (is_array($requestedSymbols) && !empty($requestedSymbols)) {
            return $this->normalizeSymbols($requestedSymbols);
        }

        if ($opportunityMode) {
            return $this->opportunityRepository->latestEligibleSymbols($opportunityLimit);
        }

        return $this->priceSnapshot->trackedSymbols();
    }

    private function portfolioSymbolSet(): array
    {
        $set = [];
        foreach ($this->portfolioRepository->findAll() as $portfolio) {
            if ($portfolio instanceof Portfolio) {
                $set[strtoupper((string) $portfolio->getSymbol())] = true;
            }
        }
        return $set;
    }

    private function watchlistSymbolSet(): array
    {
        $set = [];
        foreach ($this->watchlistRepository->findActive() as $item) {
            if ($item instanceof WatchlistItem) {
                $set[$item->getSymbol()] = true;
            }
        }
        return $set;
    }

    private function normalizeSymbols(array $symbols): array
    {
        $normalized = [];
        foreach ($symbols as $symbol) {
            $symbol = strtoupper(trim((string) $symbol));
            if (str_ends_with($symbol, '.IS')) {
                $symbol = substr($symbol, 0, -3);
            }
            if (preg_match('/^[A-Z0-9]{2,20}$/', $symbol)) {
                $normalized[] = $symbol;
            }
        }
        return array_values(array_unique($normalized));
    }

    private function runReport(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        $sendTelegram = !$dryRun && !(bool) $input->getOption('no-telegram');
        
        $opportunityMode = (bool) $input->getOption('opportunities');
        $symbols = $this->resolveSymbols(
            $input->getOption('symbol'),
            $opportunityMode,
            max(1, (int) $input->getOption('opportunity-limit'))
        );
        $reportScope = $opportunityMode ? AiSymbolReport::SCOPE_OPPORTUNITY : AiSymbolReport::SCOPE_TRACKED;

        if (empty($symbols)) {
            $io->success('Analiz edilecek portfoy/takip sembolu yok.');
            return Command::SUCCESS;
        }

        if ($dryRun) $io->warning('Dry-run: raporlar kaydedilmeyecek, Telegram gonderilmeyecek.');

        $portfolioSymbols = $this->portfolioSymbolSet();
        $watchlistSymbols = $this->watchlistSymbolSet();
        
        $skipPriceRefresh = (bool) $input->getOption('skip-price-refresh');
        if (!$skipPriceRefresh) {
            $this->priceSnapshot->refresh($symbols, $dryRun);
        }
        $historyMap = $this->historyService->fetchBatch($symbols);

                $io->title('Batch AI Raporlamasi Basliyor');
        $chunkSize = $opportunityMode ? 2 : 10;
        $symbolChunks = array_chunk($symbols, $chunkSize);
        $io->text(sprintf('%d sembol, %d grup halinde analiz edilecek...', count($symbols), count($symbolChunks)));

        $parsedData = ['telegram_report' => '', 'symbol_reports' => [], 'portfolio_comment' => ''];
        
        // BatchData'yi cumulative tutmamiz lazim ki asagida veritabanina yazarken okuyabilelim!
        $cumulativeBatchData = ['symbol_reports' => []];

        foreach ($symbolChunks as $index => $chunk) {
            $io->text(sprintf('Grup %d/%d isleniyor...', $index + 1, count($symbolChunks)));
            $batchData = $opportunityMode 
                ? $this->buildOpportunityBatchData($chunk, $io) 
                : $this->buildPortfolioBatchData($chunk, $historyMap);
                
            foreach ($batchData['symbol_reports'] as $s => $rd) {
                $cumulativeBatchData['symbol_reports'][$s] = $rd;
            }
                
            $prompt = $opportunityMode
                ? $this->buildOpportunityBatchPrompt($batchData)
                : $this->buildBatchPrompt($batchData);

            $chunkParsed = $this->askBatchAi($prompt, $batchData, $io, $opportunityMode);
            
            // Chunk sonuclarini birlestir
            if (isset($chunkParsed['telegram_report'])) {
                $parsedData['telegram_report'] .= $chunkParsed['telegram_report'] . "\n\n";
            }
            if (isset($chunkParsed['portfolio_comment'])) {
                $parsedData['portfolio_comment'] .= ($parsedData['portfolio_comment'] ? "\n\n" : "") . $chunkParsed['portfolio_comment'];
            }
            if (isset($chunkParsed['symbol_reports'])) {
                foreach ($chunkParsed['symbol_reports'] as $sym => $rep) {
                    $parsedData['symbol_reports'][$sym] = $rep;
                }
            }
            
            // rate limit beklemesi
            if ($index < count($symbolChunks) - 1) {
                $delay = (int) $input->getOption('delay');
                if ($delay > 0) sleep($delay);
            }
        }
        $batchData = $cumulativeBatchData;

        $today = new \DateTimeImmutable('today');

        foreach ($symbols as $symbol) {
            if (!isset($parsedData['symbol_reports'][$symbol])) {
                $this->logger->error("LLM '$symbol' hissesini raporda atlamis. Gecmise yazilamadi.");
                continue;
            }

                        $reportData = $parsedData['symbol_reports'][$symbol];
            $rawTechnical = $batchData['symbol_reports'][$symbol] ?? [];

            $actualTechnical = $opportunityMode ? ($rawTechnical['technical'] ?? []) : $rawTechnical;
            $sysScore = $opportunityMode ? ($rawTechnical['systemScore'] ?? 50) : ($rawTechnical['score'] ?? 50);
            $finalScore = (int) ($reportData['ai_score'] ?? $sysScore);

            $report = (new AiSymbolReport())
                ->setSymbol($symbol)
                ->setReportDate($today)
                ->setScore($finalScore)
                ->setTrendLabel((string) ($reportData['trend'] ?? 'notr'))
                ->setDecisionLabel((string) ($reportData['decision'] ?? 'notr'))
                ->setDailyComment((string) ($reportData['comment'] ?? ''))
                ->setAnalysisStatus(AiSymbolReport::ANALYSIS_SUCCESS)
                ->setReportScope($reportScope)
                ->setPrice(isset($actualTechnical['lastClose']) ? (float)$actualTechnical['lastClose'] : null)
                ->setIsPortfolio(isset($portfolioSymbols[$symbol]))
                ->setIsWatchlist(isset($watchlistSymbols[$symbol]));
            
            if (!$dryRun) $this->em->persist($report);
            
            $historyRecord = $this->aiSymbolHistoryRepository->findOneBy(['symbol' => $symbol, 'recordDate' => $today]);
            if (!$historyRecord) {
                $historyRecord = new AiSymbolHistory();
                $historyRecord->setSymbol($symbol);
                $historyRecord->setRecordDate($today);
                if (!$dryRun) $this->em->persist($historyRecord);
            }
            
            try {
                $historyRecord->setDecision($reportData['decision'] ?? 'notr');
                $historyRecord->setTrend($reportData['trend'] ?? 'notr');
                $historyRecord->setPrice($actualTechnical['lastClose'] ?? 0);
                $historyRecord->setRsi($actualTechnical['rsi14'] ?? 0);
            } catch (\Throwable $e) {
                $this->logger->error("Gecmis kaydi yazilirken '$symbol' icin hata: " . $e->getMessage());
            }

            $io->writeln("$symbol raporlandi: {$reportData['decision']} / {$reportData['trend']}");
        }

        if (!$dryRun) {
            $this->em->flush();
            $io->success("Veritabani kayitlari tamamlandi.");
        }

        if ($sendTelegram && isset($parsedData['telegram_report'])) {
            try {
                $this->telegram->sendMessage($parsedData['telegram_report']);
                $io->success("Telegram raporu basariyla gonderildi.");
            } catch (\Throwable $e) {
                $io->error("Telegram gonderim hatasi: " . $e->getMessage());
            }
        } elseif ($dryRun && isset($parsedData['telegram_report'])) {
            $io->section("TELEGRAM RAPORU (DRY RUN Ozet)");
            $io->text($parsedData['telegram_report']);
        }

        return Command::SUCCESS;
    }

    private const PORTFOLIO_SECTOR_MAP = [
        'GARAN' => 'Bankacılık',
        'AKBNK' => 'Bankacılık',
        'SKBNK' => 'Bankacılık',
        'ENJSA' => 'Enerji',
        'AKSEN' => 'Enerji',
        'ENKAI' => 'İnşaat / Taahhüt'
    ];

    private function calculateSectorDistribution(array $symbols): array
    {
        if (empty($symbols)) return [];
        $total = count($symbols);
        $counts = [];
        foreach ($symbols as $symbol) {
            $sector = self::PORTFOLIO_SECTOR_MAP[$symbol] ?? 'Diğer';
            $counts[$sector] = ($counts[$sector] ?? 0) + 1;
        }
        $distribution = [];
        foreach ($counts as $sector => $count) {
            $distribution[$sector] = round(($count / $total) * 100, 1) . '%';
        }
        arsort($distribution);
        return $distribution;
    }

    private function buildPortfolioBatchData(array $symbols, array $historyMap): array
    {
        $portfolioData = [
            'portfolio_summary' => [],
            'symbol_reports' => []
        ];

        $since = new \DateTimeImmutable('-7 days');
        $today = new \DateTimeImmutable('today');

        foreach ($symbols as $symbol) {
            try {
                $history = $historyMap[$symbol] ?? [];
                $technical = $this->technicalAnalysis->analyze(is_array($history['bars'] ?? null) ? $history['bars'] : []);
                
                $scoreResult = $this->scoring->score($technical, $history);
                $technical['score'] = $scoreResult['score'];

                $kapNews = $this->kapNewsRepository->findRecentForSymbol($symbol, $since, 3);
                $newsArray = [];
                foreach ($kapNews as $news) {
                    if ($news->getAiSummary() !== null) {
                        $newsArray[] = [
                            'date' => $news->getPublishedAt()->format('Y-m-d'),
                            'title' => $news->getTitle(),
                            'aiSummary' => $news->getAiSummary(),
                            'sentimentScore' => $news->getSentimentScore()
                        ];
                    }
                }
                $technical['news'] = $newsArray;
                
                $prevHistory = $this->aiSymbolHistoryRepository->findLatestBeforeDate($symbol, $today);
                if ($prevHistory !== null) {
                    $technical['previousStance'] = [
                        'date' => $prevHistory->getRecordDate()->format('Y-m-d'),
                        'decision' => $prevHistory->getDecision(),
                        'trend' => $prevHistory->getTrend(),
                        'price' => $prevHistory->getPrice(),
                        'rsi' => $prevHistory->getRsi()
                    ];
                } else {
                    $technical['previousStance'] = null;
                }
                
                $portfolioData['symbol_reports'][$symbol] = $technical;
            } catch (\Throwable $e) {
                $this->logger->error("JSON Data olusturulurken '$symbol' icin hata: " . $e->getMessage());
                continue;
            }
        }

        $portfolioData['portfolio_summary']['sector_distribution'] = $this->calculateSectorDistribution($symbols);
        return $portfolioData;
    }

    private function buildBatchPrompt(array $portfolioBatchData): string
    {
        return <<<EOT
Sen BAM Terminal'in profesyonel BIST portföy analiz motorusun.

YASAL ZORUNLULUK:
- HİÇBİR ZAMAN şu kelimeleri kullanma: "al", "sat", "tut", "hedef fiyat", "yükselecek", "düşecek", "kaçırılmaz", "kesinlikle".
- SADECE gözlemsel dil kullan.

VERİ KURALLARI:
1. Sana verilen JSON'daki verileri kullan. JSON'da olmayan hiçbir sayıyı UYDURMA.
2. Raporun en başına 'Portföy Risk Analizi' başlığı aç.
3. Varsa yoğunlaşma riskini (bir sektör %40'ın üzerindeyse özellikle vurgulayarak) yorumla. 
4. Sektör yüzdelerini SADECE sana verilen 'portfolio_summary.sector_distribution' alanından al.
5. Haberler (news): Haberleri sadece 'aiSummary' ve 'title' üzerinden yorumla. Haberin içinden, özetinden veya 'sentimentScore' alanından hiçbir yeni SAYIYI (tutar, oran, skor, yüzde) rapora ASLA YAZMA. Ayrıca title içinde geçen hiçbir sayıyı (tutar, oran vb.) rapora yazma, title'ı sadece haberin genel konusunu anlamak için oku, sayısal detayları asla aktarma. 'sentimentScore' (0.8 vb.) gibi değerleri sadece sözel (olumlu/olumsuz) olarak ifade et.
6. Bir hissenin 'news' dizisi boşsa, o hisse için haber veya KAP duyurusu konusuna HİÇ DEĞİNME.
7. Eğer teknik veriler (RSI, karar) ile haberin 'sentimentScore'u çelişiyorsa, çelişkiyi dengeli ve nötr bir dille yansıt.
8. Hacim (volumeRatio) değerini 'X kat' formatında yaz, yüzdeye çevirme.
9. RSI >= 70 = decision: dikkat, RSI <= 30 = decision: izle, arası = nötr.
10. Geçmiş Yorum Takibi (previousStance): Eğer bir hissenin 'previousStance' alanı null veya boş ise, o hisseyle ilgili hiçbir geçmiş zaman kıyası yapma.
11. Geçmiş ve bugünkü değerleri kıyaslarken kendi hesapladığın bir yüzde/fark üretme (örn. "%7 arttı", "5 lira düştü" deme), sadece iki değeri yan yana söyle (örn. "105 TL'den 112.50 TL'ye çıktı").

ÇIKTI FORMATI SADECE JSON OLMALIDIR:
{
  "telegram_report": "Buraya markdown formatlı akıcı rapor",
  "symbol_reports": {
    "GARAN": {
      "trend": "pozitif",
      "decision": "dikkat",
      "comment": "Teknik ve varsa haberi harmanlayan 1 cümlelik yorum"
    }
  }
}

PORTFÖY VERİSİ (JSON):
EOT . "\n" . json_encode($portfolioBatchData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }

    private function buildFallbackReport(array $portfolioBatchData, bool $opportunityMode = false): array
    {
        $telegramReport = "⚠️ <b>AI Analiz Sunucularinda yogunluk yasanmaktadir. Gun sonu ham verileriniz:</b>\n\n";
        
        $sectorDist = $portfolioBatchData['portfolio_summary']['sector_distribution'] ?? [];
        if (!empty($sectorDist)) {
            $telegramReport .= "📊 <b>Portfoy Dagilimi:</b>\n";
            foreach ($sectorDist as $sector => $pct) {
                $telegramReport .= " - $sector: $pct\n";
            }
            $telegramReport .= "\n";
        }

        $symbolReports = [];
        $symbolsData = $portfolioBatchData['symbol_reports'] ?? [];
        foreach ($symbolsData as $symbol => $data) {
            $price = $data['lastClose'] ?? 0.0;
            $rsi = $data['rsi14'] ?? 50.0;
            $trend = $data['trend'] ?? 'notr';
            
            if ($rsi >= 70) {
                $rsiLabel = 'Asiri Alim';
                $decision = 'dikkat';
            } elseif ($rsi <= 30) {
                $rsiLabel = 'Asiri Satim';
                $decision = 'izle';
            } else {
                $rsiLabel = 'Notr';
                $decision = 'notr';
            }

            $telegramReport .= sprintf(
                "▪ <b>%s</b>: Fiyat %.2f | RSI %.1f (%s) | Trend: %s\n",
                $symbol, $price, $rsi, $rsiLabel, ucfirst($trend)
            );

                        $symbolReports[$symbol] = [
                'ai_score' => $data['score'] ?? 50,
                'score' => $data['score'] ?? 50,
                'trend' => $trend,
                'decision' => $decision,
                'comment' => sprintf('Otomatik sistem raporu. RSI: %.1f, Trend: %s.', $rsi, ucfirst($trend))
            ];
        }
        $telegramReport .= "\n<i>Yatirim tavsiyesi degildir, teknik verilere dayali otonom sistem raporudur.</i>";
        return [
            'telegram_report' => $telegramReport,
            'symbol_reports' => $symbolReports
        ];
    }

    private function isCloseEnough(float $value, array $allowedList, float $margin): bool
    {
        foreach ($allowedList as $allowed) {
            if (abs($value - $allowed) <= $margin) return true;
        }
        return false;
    }

        private function verifyNoHallucination(string $aiText, array $portfolioBatchData, SymfonyStyle $io): bool
    {
        $allNumbersInSource = $this->collectAllNumbers($portfolioBatchData);

        preg_match_all('/(-?[0-9]+(?:\.[0-9]+)?)\s*%|%\s*(-?[0-9]+(?:\.[0-9]+)?)/u', $aiText, $pctMatches);
        $foundPercentages = array_filter(array_merge($pctMatches[1], $pctMatches[2]));
        foreach ($foundPercentages as $pct) {
            if (!$this->isCloseEnough((float) $pct, $allNumbersInSource, 1.5)) {
                $io->error("Halusinasyon tespiti! Uydurulan yuzde: " . $pct); $io->warning("DEBUG - allowed: " . json_encode($allNumbersInSource));
                return false; 
            }
        }

        preg_match_all('/RSI(?:\s*\([0-9]+\))?\s*[:\-]?\s*([0-9]+(?:\.[0-9]+)?)/i', $aiText, $rsiMatches);
        foreach ($rsiMatches[1] as $rsi) {
            if (!$this->isCloseEnough((float) $rsi, $allNumbersInSource, 1.0)) {
                $io->error("Halusinasyon tespiti! Uydurulan RSI: " . $rsi); return false; 
            }
        }

        preg_match_all('/MACD\s*[:\-]?\s*(?:De(?:g|ğ)er|Sinyal)?\s*(-?[0-9]+(?:\.[0-9]+)?)/i', $aiText, $macdMatches);
        foreach ($macdMatches[1] as $macd) {
            if (!$this->isCloseEnough((float) $macd, $allNumbersInSource, 0.1)) {
                $io->error("Halusinasyon tespiti! Uydurulan MACD: " . $macd); return false;
            }
        }

        preg_match_all('/([0-9]+(?:\.[0-9]+)?)\s*kat/ui', $aiText, $volMatches);
        foreach ($volMatches[1] as $vol) {
            if (!$this->isCloseEnough((float) $vol, $allNumbersInSource, 0.1)) {
                $io->error("Halusinasyon tespiti! Uydurulan Hacim: " . $vol); return false;
            }
        }

        $cleanText = preg_replace('/%[0-9.]+|[0-9.]+%/i', '', $aiText);
        $cleanText = preg_replace('/RSI(?:\s*\([0-9]+\))?\s*[:\-]?\s*[0-9.]+/i', '', $cleanText);
        $cleanText = preg_replace('/MACD\s*[:\-]?\s*(?:De(?:g|ğ)er|Sinyal)?\s*-?[0-9.]+/i', '', $cleanText);
        $cleanText = preg_replace('/[0-9.]+\s*kat/ui', '', $cleanText);
        $cleanText = preg_replace('/\b(?:202[0-9]|19[0-9]{2})\b/i', '', $cleanText);
        $cleanText = preg_replace('/\b[0-9]+\s*(?:gunluk|haftalik|aylik|saatlik|periyotluk)\b/ui', '', $cleanText);
        $cleanText = preg_replace('/\b[0-9]+\s*(?:Ocak|Subat|Mart|Nisan|Mayis|Haziran|Temmuz|Agustos|Eylul|Ekim|Kasim|Aralik)\b/ui', '', $cleanText);
        // Ozel olarak firsat modundaki skorlari (ornegin 78/100, skorum 78, vb.) temizle
        $cleanText = preg_replace('/(?:skor|score)[^\d]+([0-9]+(?:\.[0-9]+)?)/ui', '', $cleanText);
        $cleanText = preg_replace('/([0-9]+(?:\.[0-9]+)?)\s*\/\s*100/ui', '', $cleanText);
        $cleanText = preg_replace('/\b(?:100|30|50)\b/i', '', $cleanText);

        preg_match_all('/([0-9]{2,}(?:\.[0-9]+)?)/u', $cleanText, $priceMatches);
        
        foreach ($priceMatches[1] as $price) {
            $p = (float) str_replace(",", ".", $price);
            if (in_array(round($p), [14, 20, 50, 100, 200, 40])) continue;
            
            // 1-10 arasi KUSURATSIZ sayilar (1, 2, 3...) metin icinde adet/sira bildirdigi icin atla.
            // Ama kusuratli fiyatlar (orn. 5.50) kontrol edilsin.
            if ($p == round($p) && $p <= 10) continue;

            if (!$this->isCloseEnough($p, $allNumbersInSource, 2.0)) {
                $io->error("Halusinasyon tespiti! Uydurulan sayi/fiyat: " . $price);
                $pos = strpos($aiText, (string)$price);
                if ($pos !== false) {
                    $context = substr($aiText, max(0, $pos - 30), 60);
                    $io->warning("Baglam (Context): ..." . str_replace("\n", " ", $context) . "...");
                } else {
                    $io->warning("Baglam (Context): Bulunamadi (Bicim farkliligi olabilir).");
                }
                return false; 
            }
        }
        
        return true;
    }
    private function askBatchAi(string $prompt, array $batchData, SymfonyStyle $io, bool $opportunityMode): array
    {
        
        try {
            $reportText = $this->aiProvider->askJson($prompt);
            $parsedData = json_decode($reportText, true);

            if (json_last_error() !== JSON_ERROR_NONE || !is_array($parsedData)) {
                $this->logger->warning('Yapay Zeka bozuk JSON dondurdu: ' . $reportText . ' | 2. sans veriliyor.');
                $retryPrompt = $prompt . "\n\nUYARI: Onceki yanitin bozuktu. SADECE gecerli JSON dondur.";
                $reportText = $this->aiProvider->askJson($retryPrompt);
                $parsedData = json_decode($reportText, true);

                if (json_last_error() !== JSON_ERROR_NONE || !is_array($parsedData)) {
                    $this->logger->error('Yapay Zeka 2. denemede de JSON bozdu: ' . $reportText . ' | Fallback tetiklendi.');
                    return $opportunityMode ? $this->buildOpportunityFallbackReport($batchData) : $this->buildFallbackReport($batchData, $opportunityMode);
                }
            }

            if (!$this->verifyNoHallucination($reportText, $batchData, $io)) {
                $this->logger->warning('Yapay Zeka Halusinasyon yapti, 2. sans veriliyor.');
                $retryPrompt = $prompt . "\n\nUYARI: Yanitinda verilerde olmayan sayilar uydurdun! SADECE JSON'daki rakamlari kullan. Tekrar yaz.";
                $reportText = $this->aiProvider->askJson($retryPrompt);
                $parsedData = json_decode($reportText, true);

                if (json_last_error() !== JSON_ERROR_NONE || !is_array($parsedData) || !$this->verifyNoHallucination($reportText, $batchData, $io)) {
                    $this->logger->error('Yapay Zeka 2. denemede de sayi uydurdu veya JSON bozdu. Fallback tetiklendi.');
                    return $opportunityMode ? $this->buildOpportunityFallbackReport($batchData) : $this->buildFallbackReport($batchData, $opportunityMode);
                }
            }
            return $parsedData;
        } catch (\Throwable $e) {
            $this->logger->error('LLM API Hatasi: ' . $e->getMessage());
            return $opportunityMode ? $this->buildOpportunityFallbackReport($batchData) : $this->buildFallbackReport($batchData, $opportunityMode);
        }
    }

    private function buildOpportunityBatchData(array $symbols, SymfonyStyle $io): array
    {
        $data = ['symbol_reports' => []];
        $historyMap = $this->historyService->fetchBatch($symbols);
        foreach ($symbols as $symbol) {
            try {
                $history = $historyMap[$symbol] ?? null;
                if ($history === null || ($history['status'] ?? '') !== 'ok') continue;
                
                $bars = $history['bars'] ?? [];
                $technical = $this->technicalAnalysis->analyze(is_array($bars) ? $bars : []);
                
                $candidate = $this->opportunityRepository->findOneBy(['symbol' => $symbol], ['createdAt' => 'DESC']);
                
                $data['symbol_reports'][$symbol] = [
                    'price' => (is_array($bars) && !empty($bars)) ? end($bars)['close'] : 0,
                    'technical' => $technical,
                    'systemScore' => $candidate ? $candidate->getScore() : 50,
                    'systemReasons' => $candidate && $candidate->getReasons() ? implode(', ', $candidate->getReasons()) : 'Veri yok'
                ];
            } catch (\Throwable $e) {
                $this->logger->error(sprintf("Firsat Batch hazirlama hatasi %s: %s", $symbol, $e->getMessage()));
            }
        }
        return $data;
    }

    private function buildOpportunityBatchPrompt(array $batchData): string
    {
        return <<<EOT
Sen BIST odakli, 'dipten donus' stratejisi uygulayan kisisel bir karar destek analistisin. 
Amacin fiyati zaten ucmus/asiri isinmis hisseleri DEGIL; duzeltme yasamis, ana desteklere (SMA50/SMA200) yaklasmis ve YENI toparlanma sinyali veren hisseleri erken tespit etmektir.
Sana verilen JSON'da algoritmamizdan yuksek skor almis FIRSAT ADAYI hisselerin listesi bulunmaktadir.

YASAL ZORUNLULUK:
- "al", "sat", "tut", "hedef fiyat", "yükselecek", "düşecek", "kaçırılmaz", "kesinlikle" kelimelerini KULLANMA.

KURALLAR:
1. SADECE JSON'da verilen sembolleri ve verileri kullan. Olmayan sayi uydurma.
2. Raporun en başına 'BAM BIST Fırsat Radarı' başlığı aç.
3. Bir hisse RSI > 65 ve/veya SMA20'nin %7+ uzerindeyse: bu hisseyi 'gec kalinmis/riskli' say ve AI skorunu 55'in altinda tut. En yuksek skorlari (70+) sadece SU AN destekten YENI donmeye baslayanlara ver.
4. Sistem skoru (systemScore) ve gerekcelerini (systemReasons) baz alarak kendi 0-100 arasi AI skorunu olustur (bunu yorumda belirt).
5. 'trend' sadece: negatif, notr, pozitif.
6. 'decision' sadece: takip_et, bekle, riskli.

YASAL KURAL: HICBIR ACIKLAMA VEYA GIDIS YOLU (REASONING) YAZMA. CIKTIN SADECE VE SADECE { ILE BASLAYAN GECERLI BIR JSON OLMALIDIR.
CIKTI FORMATI GECERLI JSON OLMALIDIR:
{
  "telegram_report": "Buraya markdown formatinda akici ve gruplandirilmis firsat raporu",
  "symbol_reports": {
    "GARAN": {
      "trend": "pozitif",
      "decision": "takip_et",
      "comment": "Sistem skoru 80 iken benim skorum 75 cunku..."
    }
  }
}

TELEGRAM RAPORU FORMATI (telegram_report alanina YAZ):
🎯 BAM BIST Firsat Radari

🔥 AI Onayli Firsatlar (Potansiyel Alim - AI Skoru > 65)
1. SEMBOL - AI_SKORU/100 (Sistem Skoru: X)
   [Buraya hisse yorumu (comment'in aynisi veya detaylisi)]
   
⚠️ Teknik Iyi Ama AI'dan Gecemeyenler / Notrler
- SEMBOL (AI_SKORU/100): Sistem skoru X iken benim skorum Y cunku... [Yorum]

VERI (JSON):
EOT . "\n" . json_encode($batchData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }

    private function buildOpportunityFallbackReport(array $batchData): array
    {
        $md = "🎯 <b>BAM BIST Firsat Radari (Sistem Tarafindan Uretildi)</b>\n\n";
        $md .= "⚠️ <i>Yapay Zeka sunucularina ulasilamadi, asagidaki veriler teknik tarama sonuclaridir.</i>\n\n";

        $symbolsData = $batchData['symbol_reports'] ?? [];
        
        $parsedData = [
            'telegram_report' => '',
            'symbol_reports' => []
        ];

        foreach ($symbolsData as $symbol => $data) {
            $score = $data['systemScore'] ?? 50;
            $reasons = $data['systemReasons'] ?? 'Veri yok.';
            
            $md .= "▪ <b>{$symbol}</b> - Skor: {$score}/100\n";
            $md .= "  <i>{$reasons}</i>\n\n";

            $parsedData['symbol_reports'][$symbol] = [
                'trend' => 'notr',
                'decision' => 'notr',
                'comment' => $reasons
            ];
            
            $this->logger->info("$symbol firsat fallback raporlandi: notr / notr");
        }

        $md .= "\n<i>Yatirim tavsiyesi degildir, teknik verilere dayali otonom sistem raporudur.</i>\n";
        $parsedData['telegram_report'] = $md;

        return $parsedData;
    }

    private function parseJsonSafely(string $text): ?array
    {
        $parsed = json_decode($text, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($parsed)) {
            return $parsed;
        }

        // Kurtarma Denemesi: Ilk { ile son } arasini al
        $start = strpos($text, '{');
        $end = strrpos($text, '}');
        if ($start !== false && $end !== false && $end > $start) {
            $jsonStr = substr($text, $start, $end - $start + 1);
            $parsed = json_decode($jsonStr, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($parsed)) {
                $this->logger->info('JSON parser: Bozuk metin icinden JSON basariyla kurtarildi.');
                return $parsed;
            }
        }

        return null;
    }

    private function collectAllNumbers(array $data): array
    {
        $numbers = [];
        array_walk_recursive($data, function($item) use (&$numbers) {
            if (is_numeric($item)) {
                $numbers[] = (float) $item;
            } elseif (is_string($item)) {
                preg_match_all('/-?[0-9]+(?:\.[0-9]+)?/', $item, $matches);
                foreach ($matches[0] as $match) {
                    $numbers[] = (float) $match;
                }
            }
        });
        return array_unique($numbers);
    }
}
