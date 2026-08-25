<?php

namespace App\Service;

class OpportunityScoringService
{
    /**
     * @param array<string, mixed> $technical
     * @param array<string, mixed> $history
     * @return array{score: int, status: string, reasons: string[]}
     */
    public function score(array $technical, array $history): array
    {
        if (($technical['status'] ?? null) !== 'ok') {
            return ['score' => 0, 'status' => 'missing', 'reasons' => ['Yeterli fiyat tarihcesi yok.']];
        }

        $stale = !empty($history['isStale']) || ($history['status'] ?? null) !== 'ok';
        $score = 50.0;
        $reasons = [];

        // 1. Şişmiş (Overextended) Hisseleri Cezalandırma
        $lastClose = $this->number($technical['lastClose'] ?? null);
        $sma = is_array($technical['sma'] ?? null) ? $technical['sma'] : [];
        $sma20 = $this->number($sma['20'] ?? null);
        
        if ($sma20 > 0) {
            $distanceFromSma20 = ($lastClose - $sma20) / $sma20;
            if ($distanceFromSma20 > 0.15) {
                $score -= 25;
                $reasons[] = 'Fiyat SMA20den aşırı uzaklaşmış (%15+), çok şişmiş.';
            } elseif ($distanceFromSma20 > 0.10) {
                $score -= 20;
                $reasons[] = 'Fiyat kısa vadeli ortalamadan çok uzaklaşmış (şişmiş).';
            } elseif ($distanceFromSma20 > 0.07) {
                $score -= 10;
                $reasons[] = 'Fiyat SMA20den belirgin uzaklaşmaya başlıyor.';
            }
        }

        // 2. Desteğe Gelenleri Ödüllendirme (Ana Strateji) & SMA Eğimi
        $sma50 = $this->number($sma['50'] ?? null);
        $sma200 = $this->number($sma['200'] ?? null);
        $sma50Slope = $this->number($sma['50_slope'] ?? null);
        $sma200Slope = $this->number($sma['200_slope'] ?? null);

        $validSupports = [];
        $invalidSupports = [];
        // Bu esik degerleri (-0.8 / -1.5) kesin degil, baslangic tahmini - ileride backtest yaparken kalibre edilecek
        $slopeThresholds = ['50' => -0.8, '200' => -1.5]; 

        foreach (['50' => ['val' => $sma50, 'slope' => $sma50Slope], '200' => ['val' => $sma200, 'slope' => $sma200Slope]] as $period => $data) {
            $average = $data['val'];
            $slope = $data['slope'];
            
            if ($average > 0) {
                $distance = abs($lastClose - $average) / $average;
                if ($distance <= 0.03) {
                    if ($slope > $slopeThresholds[$period]) { // Yatay veya yükseliyorsa
                        $validSupports[] = $period;
                    } else { // Düşen eğimdeyse destek sayılmaz
                        $invalidSupports[] = $period;
                    }
                }
            }
        }

        if (count($validSupports) === 2) {
            $score += 20;
            $reasons[] = 'Cift ana destek (SMA50+SMA200) cakismasi - guclu bolge.';
        } elseif (count($validSupports) === 1) {
            $score += 15;
            $reasons[] = "Ana desteğe (SMA{$validSupports[0]}) yakın ve destek eğimi pozitif, tepki potansiyeli yüksek.";
        }

        foreach ($invalidSupports as $period) {
            $score -= 5;
            $reasons[] = "Fiyat SMA{$period}'ye yakın ama ortalama eğimi negatif (düşüş trendi).";
        }

        // 3. RSI Yorumlaması
        $rsi = $this->number($technical['rsi14'] ?? null, 50);
        $rsiSlope = $this->number($technical['rsi14_slope'] ?? null);

        if ($rsi > 75) {
            $score -= 15;
            $reasons[] = 'RSI aşırı alım bölgesinde, düzeltme riski var.';
        } elseif ($rsi > 65) {
            $score -= 5;
            $reasons[] = 'RSI ısınma bölgesine girmiş (65-75), dikkatli olunmalı.';
        } elseif ($rsi >= 30 && $rsi <= 45) {
            if ($rsiSlope > 0) { // Dibi görüp dönüyorsa
                $score += 15;
                $reasons[] = 'RSI aşırı satımdan dönüyor, toparlanma sinyali.';
            } else { // Düşüyor veya yataysa
                $score += 5;
                $reasons[] = 'RSI aşırı satım bölgesinde (dönüş zayıf).';
            }
        } elseif ($rsi < 30) {
            $score += 5;
            $reasons[] = 'RSI dipte sürünüyor, çok ucuz.';
        } elseif ($rsi > 45 && $rsi <= 65) {
            $score += 5;
        }

        // 4. Hacim Teyidi
        $volumeRatio = $this->number($technical['volumeRatio20'] ?? null, 1);
        if ($volumeRatio >= 1.2) {
            $score += 10;
            $reasons[] = 'Hacim ortalamanın belirgin üzerinde (teyit).';
        }

        // 5. MACD Momentum Teyidi
        $macd = is_array($technical['macd'] ?? null) ? $technical['macd'] : [];
        $histogram = $this->number($macd['histogram'] ?? null);
        if ($histogram > 0) {
            $score += 10; // Daha önce 5'ti, şimdi 10 (teyit olarak daha değerli)
            $reasons[] = 'MACD momentumu pozitif.';
        } elseif ($histogram < 0) {
            $score -= 5;
        }

        // 6. XU100 (Endeks) Yön Filtresi
        $xu100 = is_array($technical['xu100'] ?? null) ? $technical['xu100'] : [];
        $xu100_last = $this->number($xu100['lastClose'] ?? null);
        $xu100_sma50 = $this->number($xu100['sma']['50'] ?? null);
        $xu100_sma50Slope = $this->number($xu100['sma']['50_slope'] ?? null);
        
        if ($xu100_last > 0 && $xu100_sma50 > 0) {
            if ($xu100_last < $xu100_sma50 && $xu100_sma50Slope < 0) {
                $score -= 10;
                $reasons[] = 'Endeks (XU100) düşüş trendinde, sistemik risk yüksek.';
            } elseif ($xu100_last > $xu100_sma50 && $xu100_sma50Slope > 0) {
                $score += 5;
                $reasons[] = 'Endeks (XU100) yükseliş trendinde (rüzgar arkada).';
            }
        }

        if ($stale) {
            $score -= 15;
            $reasons = array_slice($reasons, 0, 4);
            $reasons[] = 'Tarihsel veri stale; AI adayligina alinmadi.';
        }

        return [
            'score' => max(0, min(100, (int) round($score))),
            'status' => $stale ? 'stale' : 'eligible',
            'reasons' => array_slice($reasons, 0, 8),
        ];
    }

    private function number(mixed $value, float $default = 0.0): float
    {
        return is_numeric($value) ? (float) $value : $default;
    }

    private function bounded(float $value, float $min, float $max): float
    {
        return max($min, min($max, $value));
    }
}
