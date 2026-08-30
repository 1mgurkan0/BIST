<?php

namespace App\Command;

use App\Interface\AiProviderInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:test:ai-consistency',
    description: 'Nvidia Ultra 3 AI modelinin portfoy raporu tutarliligini 3 kez istek atarak test eder.',
)]
class AiConsistencyTestCommand extends Command
{
    private const FORBIDDEN_WORDS = [
        '/\bal\b/iu', '/\bsat\b/iu', '/\btut\b/iu', 
        '/hedef fiyat/iu', '/yÃƒÆ’Ã‚Â¼kselecek/iu', '/dÃƒÆ’Ã‚Â¼Ãƒâ€¦Ã…Â¸ecek/iu', 
        '/kaÃƒÆ’Ã‚Â§Ãƒâ€žÃ‚Â±rÃƒâ€žÃ‚Â±lmaz/iu', '/kesinlikle/iu'
    ];

    public function __construct(
        private readonly AiProviderInterface $aiProvider
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('AI Tutarlilik Testi (Nvidia Ultra 3)');
        $io->warning('Bu komut gercek API kotasi harcayarak 3 adet istek atacaktir.');

        $portfolioData = [
            'GARAN' => [
                'lastClose' => 112.50,
                'rsi14' => 78.4,
                'trend' => 'pozitif',
                'macd' => ['signal' => 1.8, 'value' => 2.1],
                'volumeRatio' => 1.5,
                'support20' => 105.0,
                'resistance20' => 115.0
            ],
            'SKBNK' => [
                'lastClose' => 4.10,
                'rsi14' => 28.5,
                'trend' => 'negatif',
                'macd' => ['signal' => -0.5, 'value' => -0.8],
                'volumeRatio' => 0.8,
                'support20' => 4.00,
                'resistance20' => 4.50
            ]
        ];

        $prompt = $this->getPrompt($portfolioData);
        $results = [];

        for ($i = 1; $i <= 3; $i++) {
            $io->text("Deneme $i/3: Istek atiliyor...");
            try {
                $rawResponse = $this->aiProvider->askJson($prompt);
                $parsed = json_decode($rawResponse, true);
                
                if (json_last_error() !== JSON_ERROR_NONE || !isset($parsed['telegram_report']) || !isset($parsed['symbol_reports'])) {
                    $io->error("Deneme $i basarisiz: Gecersiz JSON veya eksik schema.");
                    return Command::FAILURE;
                }

                $fullTextToCheck = $parsed['telegram_report'];
                foreach ($parsed['symbol_reports'] as $sym => $report) {
                    $fullTextToCheck .= " " . ($report['comment'] ?? '');
                }

                if ($this->hasForbiddenWords($fullTextToCheck, $io)) {
                    return Command::FAILURE;
                }
                if (!$this->verifyNoHallucination($fullTextToCheck, $portfolioData, $io)) {
                    return Command::FAILURE;
                }

                $results[] = $parsed['symbol_reports'];
                $io->success("Deneme $i basarili. (Format, halusinasyon ve kelime kurallari gecerli)");
                
            } catch (\Throwable $e) {
                $io->error("API Hatasi: " . $e->getMessage());
                return Command::FAILURE;
            }
        }

        $io->section('Tutarlilik Analizi Sonuclari (Decision ve Trend)');
        
        $symbols = array_keys($portfolioData);
        foreach ($symbols as $sym) {
            $decisions = [];
            $trends = [];
            for ($i = 0; $i < 3; $i++) {
                $decisions[] = $results[$i][$sym]['decision'] ?? 'yok';
                $trends[] = $results[$i][$sym]['trend'] ?? 'yok';
            }

            if (count(array_unique($decisions)) !== 1) {
                $io->error("$sym hissesi icin 'decision' tutarsiz! Ciktilar: " . implode(', ', $decisions));
                return Command::FAILURE;
            }
            if (count(array_unique($trends)) !== 1) {
                $io->error("$sym hissesi icin 'trend' tutarsiz! Ciktilar: " . implode(', ', $trends));
                return Command::FAILURE;
            }
            
            $io->writeln("<info>[$sym]</info> Tutarlilik OK. (Decision: {$decisions[0]}, Trend: {$trends[0]})");
        }

        $io->success("Test %100 Basarili! Nvidia modeli 3 denemede de ayni yapisal kararlari uretti ve yasal kurallara uydu.");
        return Command::SUCCESS;
    }

    private function getPrompt(array $portfolioData): string
    {
        return <<<EOT
Sen BAM Terminal'in profesyonel BIST portfoy analiz motorusun.

YASAL ZORUNLULUK:
- HICBIR ZAMAN su kelimeleri kullanma: "al", "sat", "tut", "hedef fiyat", "yÃƒÆ’Ã‚Â¼kselecek", "dÃƒÆ’Ã‚Â¼Ãƒâ€¦Ã…Â¸ecek", "kaÃƒÆ’Ã‚Â§Ãƒâ€žÃ‚Â±rÃƒâ€žÃ‚Â±lmaz", "kesinlikle".
- SADECE gozlemsel dil kullan.

VERI KURALLARI:
1. Sana verilen JSON'daki 'lastClose', 'support20', 'resistance20', 'rsi14', 'macd.signal', 'volumeRatio' alanlarini kullan.
2. JSON'da olmayan hicbir sayiyi (fiyat, oran, seviye) UYDURMA.
3. Hacim (volumeRatio) degerini 'X kat' formatinda yaz, yuzdeye cevirme.
4. RSI >= 70 = decision: dikkat, RSI <= 30 = decision: izle, arasi = notr.

CIKTI FORMATI SADECE JSON OLMALIDIR:
{
  "telegram_report": "Buraya markdown formatli akici rapor",
  "symbol_reports": {
    "GARAN": {
      "trend": "pozitif",
      "decision": "dikkat",
      "comment": "RSI bazli 1 cumlelik yorum"
    }
  }
}

PORTFOY VERISI (JSON):
EOT . "\n" . json_encode($portfolioData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }

        // TODO(YAYIN ONCESI KRITIK): Yasakli kelime sansuru su an word-boundary kullanmiyor,
    // "satis", "saticili" gibi kelimeleri de bozabilir. Yayina acmadan once mutlaka:
    // 1. preg_replace('/\bKELIME\b/iu', ...) ile word-boundary'li regex'e gecilecek
    // 2. Yasakli kelime listesi uzunluk sirasina gore islenecek (cok kelimeli ifadeler once)
    // 3. Replace sonrasi tekrar hasForbiddenWords ile dogrulanacak
    // 4. Hem telegram_report hem symbol_reports[].comment alanlarina uygulanacak
    // 5. AiConsistencyTestCommand tekrar calistirilip dogrulanacak
    // Detay: [Notion linkin buraya]
    private function hasForbiddenWords(string $text, SymfonyStyle $io): bool
    {
        foreach (self::FORBIDDEN_WORDS as $regex) {
            if (preg_match($regex, $text, $matches)) {
                $io->error("Yasakli kelime/ibare bulundu: '" . $matches[0] . "'");
                return true;
            }
        }
        return false;
    }

    private function verifyNoHallucination(string $aiText, array $portfolioTechnicalData, SymfonyStyle $io): bool
    {
        $allowedPrices = [];
        $allowedPercentages = [];
        $allowedRsi = [];
        $allowedMacd = [];
        $allowedVolume = [];

        foreach ($portfolioTechnicalData as $symbol => $data) {
            if (isset($data['lastClose'])) $allowedPrices[] = (float) $data['lastClose'];
            if (isset($data['support20'])) $allowedPrices[] = (float) $data['support20'];
            if (isset($data['resistance20'])) $allowedPrices[] = (float) $data['resistance20'];
            if (isset($data['rsi14'])) $allowedRsi[] = round((float) $data['rsi14']);
            if (isset($data['macd']['value'])) $allowedMacd[] = (float) $data['macd']['value'];
            if (isset($data['macd']['signal'])) $allowedMacd[] = (float) $data['macd']['signal'];
            if (isset($data['volumeRatio'])) $allowedVolume[] = (float) $data['volumeRatio'];
        }

        $cleanText = preg_replace('/%[0-9.]+|[0-9.]+%/i', '', $aiText);
        $cleanText = preg_replace('/RSI[^0-9]*[0-9.]+/i', '', $cleanText);
        $cleanText = preg_replace('/MACD[^0-9-]*-[0-9.]+/i', '', $cleanText);
        $cleanText = preg_replace('/[0-9.]+\s*kat/ui', '', $cleanText);
        $cleanText = preg_replace('/\b(?:202[0-9]|19[0-9]{2})\b/i', '', $cleanText);
        $cleanText = preg_replace('/\b[0-9]+\s*(?:gunluk|haftalik|aylik|saatlik)\b/ui', '', $cleanText);
        $cleanText = preg_replace('/\b[0-9]+\s*(?:Ocak|Subat|Mart|Nisan|Mayis|Haziran|Temmuz|Agustos|Eylul|Ekim|Kasim|Aralik)\b/ui', '', $cleanText);
        $cleanText = preg_replace('/\b(?:100|30|50)\b/i', '', $cleanText);

        preg_match_all('/([0-9]{2,}(?:\.[0-9]+)?)/u', $cleanText, $priceMatches);
        foreach ($priceMatches[1] as $price) {
            if ((float)$price > 10) {
                $matched = false;
                foreach (array_merge($allowedPrices, $allowedRsi, $allowedMacd, $allowedVolume, $allowedPercentages) as $allowed) {
                    if (abs((float)$price - $allowed) <= 0.5) $matched = true;
                }
                if (!$matched) {
                    $io->error("Halusinasyon tespiti! Uydurulan fiyat: " . $price);
                    return false;
                }
            }
        }
        return true;
    }
}