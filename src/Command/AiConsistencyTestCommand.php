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
        '/hedef fiyat/iu', '/yükselecek/iu', '/düşecek/iu', 
        '/kaçırılmaz/iu', '/kesinlikle/iu'
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

        // Yeni Nested Veri Yapisi
        $portfolioBatchData = [
            'portfolio_summary' => [
                'sector_distribution' => [
                    'Bankacılık' => '50%',
                    'Enerji' => '50%'
                ]
            ],
            'symbol_reports' => [
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
            ]
        ];

        $prompt = $this->getPrompt($portfolioBatchData);
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
                if (!$this->verifyNoHallucination($fullTextToCheck, $portfolioBatchData, $io)) {
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
        
        $symbols = array_keys($portfolioBatchData['symbol_reports']);
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

    private function getPrompt(array $portfolioBatchData): string
    {
        return <<<EOT
Sen BAM Terminal'in profesyonel BIST portfoy analiz motorusun.

YASAL ZORUNLULUK:
- HICBIR ZAMAN su kelimeleri kullanma: "al", "sat", "tut", "hedef fiyat", "yükselecek", "düşecek", "kaçırılmaz", "kesinlikle".
- SADECE gozlemsel dil kullan.

VERI KURALLARI:
1. Sana verilen JSON'daki verileri kullan. JSON'da olmayan hiçbir sayıyı UYDURMA.
2. Raporun en başına 'Portföy Risk Analizi' başlığı aç.
3. Varsa yoğunlaşma riskini (bir sektör %40'ın üzerindeyse özellikle vurgulayarak) yorumla. 
4. Ancak bu yüzdeleri SADECE sana verilen 'portfolio_summary.sector_distribution' alanından al, asla kendi kendine hisse sayıp yüzde hesaplamaya kalkma.
5. Hacim (volumeRatio) degerini 'X kat' formatinda yaz, yuzdeye cevirme.
6. RSI >= 70 = decision: dikkat, RSI <= 30 = decision: izle, arasi = notr.

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
EOT . "\n" . json_encode($portfolioBatchData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
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

    private function isCloseEnough(float $value, array $allowedList, float $margin): bool
    {
        foreach ($allowedList as $allowed) {
            if (abs($value - $allowed) <= $margin) return true;
        }
        return false;
    }

    private function verifyNoHallucination(string $aiText, array $portfolioBatchData, SymfonyStyle $io): bool
    {
        $allowedPrices = [];
        $allowedPercentages = [];
        $allowedRsi = [];
        $allowedMacd = [];
        $allowedVolume = [];

        $symbolReports = $portfolioBatchData['symbol_reports'] ?? [];
        foreach ($symbolReports as $symbol => $data) {
            if (isset($data['lastClose'])) $allowedPrices[] = (float) $data['lastClose'];
            if (isset($data['support20'])) $allowedPrices[] = (float) $data['support20'];
            if (isset($data['resistance20'])) $allowedPrices[] = (float) $data['resistance20'];
            if (isset($data['rsi14'])) $allowedRsi[] = round((float) $data['rsi14']);
            if (isset($data['macd']['value'])) $allowedMacd[] = (float) $data['macd']['value'];
            if (isset($data['macd']['signal'])) $allowedMacd[] = (float) $data['macd']['signal'];
            if (isset($data['volumeRatio'])) $allowedVolume[] = (float) $data['volumeRatio'];
            
            if (isset($data['returns'])) {
                foreach ($data['returns'] as $pct) {
                    $allowedPercentages[] = round((float) $pct, 1);
                }
            }
        }

        $sectorDistribution = $portfolioBatchData['portfolio_summary']['sector_distribution'] ?? [];
        foreach ($sectorDistribution as $sector => $pctStr) {
            $val = (float) str_replace('%', '', $pctStr);
            $allowedPercentages[] = round($val, 1);
        }

        preg_match_all('/(?:%|-)\s*([0-9]+(?:\.[0-9]+)?)|([0-9]+(?:\.[0-9]+)?)\s*%/u', $aiText, $pctMatches);
        $foundPercentages = array_filter(array_merge($pctMatches[1], $pctMatches[2]));
        foreach ($foundPercentages as $pct) {
            if (!$this->isCloseEnough((float) $pct, $allowedPercentages, 1.5)) {
                $io->error("Halusinasyon tespiti! Uydurulan yuzde: " . $pct);
                return false; 
            }
        }

        preg_match_all('/RSI[^0-9]*([0-9]+(?:\.[0-9]+)?)/i', $aiText, $rsiMatches);
        foreach ($rsiMatches[1] as $rsi) {
            if (!$this->isCloseEnough((float) $rsi, $allowedRsi, 1.0)) {
                $io->error("Halusinasyon tespiti! Uydurulan RSI: " . $rsi);
                return false; 
            }
        }

        preg_match_all('/MACD[^0-9-]*(-?[0-9]+(?:\.[0-9]+)?)/i', $aiText, $macdMatches);
        foreach ($macdMatches[1] as $macd) {
            if (!$this->isCloseEnough((float) $macd, $allowedMacd, 0.1)) return false; 
        }

        preg_match_all('/([0-9]+(?:\.[0-9]+)?)\s*kat/ui', $aiText, $volMatches);
        foreach ($volMatches[1] as $vol) {
            if (!$this->isCloseEnough((float) $vol, $allowedVolume, 0.1)) return false; 
        }

        $cleanText = preg_replace('/%[0-9.]+|[0-9.]+%/i', '', $aiText);
        $cleanText = preg_replace('/RSI[^0-9]*[0-9.]+/i', '', $cleanText);
        $cleanText = preg_replace('/MACD[^0-9-]*-[0-9.]+/i', '', $cleanText);
        $cleanText = preg_replace('/[0-9.]+\s*kat/ui', '', $cleanText);
        
        $cleanText = preg_replace('/\b(?:202[0-9]|19[0-9]{2})\b/i', '', $cleanText);
        $cleanText = preg_replace('/\b[0-9]+\s*(?:gunluk|haftalik|aylik|saatlik|periyotluk)\b/ui', '', $cleanText);
        $cleanText = preg_replace('/\b[0-9]+\s*(?:Ocak|Subat|Mart|Nisan|Mayis|Haziran|Temmuz|Agustos|Eylul|Ekim|Kasim|Aralik)\b/ui', '', $cleanText);
        $cleanText = preg_replace('/\b(?:100|30|50)\b/i', '', $cleanText);

        $allAllowed = array_merge($allowedPrices, $allowedRsi, $allowedMacd, $allowedVolume, $allowedPercentages);
        preg_match_all('/([0-9]{2,}(?:\.[0-9]+)?)/u', $cleanText, $priceMatches);
        
        foreach ($priceMatches[1] as $price) {
            if ((float)$price > 10) {
                if (!$this->isCloseEnough((float) $price, $allAllowed, 0.5)) {
                    $io->error("Halusinasyon tespiti! Uydurulan sayi/fiyat: " . $price);
                    return false;
                }
            }
        }

        return true;
    }
}