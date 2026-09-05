<?php

namespace App\Service;

use App\Entity\OpportunityCandidate;

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
                $reasons[] = sprintf('Fiyat SMA20den %%%.1f yukarida (esik: %%15 uzeri) - asiri uzaklasmis ve cok sismis.', $distanceFromSma20 * 100);
            } elseif ($distanceFromSma20 > 0.10) {
                $score -= 20;
                $reasons[] = sprintf('Fiyat SMA20den %%%.1f yukarida (esik: %%10-15 arasi) - kisa vadeli ortalamadan uzaklasmis (sismis).', $distanceFromSma20 * 100);
            } elseif ($distanceFromSma20 > 0.07) {
                $score -= 10;
                $reasons[] = sprintf('Fiyat SMA20den %%%.1f yukarida (esik: %%7-10 arasi) - belirgin uzaklasmaya basliyor.', $distanceFromSma20 * 100);
            }
        }

        // 2. Desteğe Gelenleri Ödüllendirme (Ana Strateji) & SMA Eğimi
        $sma50 = $this->number($sma['50'] ?? null);
        $sma200 = $this->number($sma['200'] ?? null);
        $sma50Slope = $this->number($sma['50_slope'] ?? null);
        $sma200Slope = $this->number($sma['200_slope'] ?? null);

        $validSupports = [];
        $invalidSupports = [];
        $distances = [];
        // Bu esik degerleri (-0.8 / -1.5) kesin degil, baslangic tahmini - ileride backtest yaparken kalibre edilecek
        $slopeThresholds = ['50' => -0.8, '200' => -1.5]; 

        foreach (['50' => ['val' => $sma50, 'slope' => $sma50Slope], '200' => ['val' => $sma200, 'slope' => $sma200Slope]] as $period => $data) {
            $average = $data['val'];
            $slope = $data['slope'];
            
            if ($average > 0) {
                $distance = abs($lastClose - $average) / $average;
                if ($distance <= 0.03) {
                    $distances[$period] = ['dist' => $distance, 'slope' => $slope];
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
            $reasons[] = sprintf('Cift ana destek (SMA50+SMA200) cakismasi - guclu bolge (SMA50 mesafe: %%%.1f egim: %%%.1f, SMA200 mesafe: %%%.1f egim: %%%.1f).', 
                $distances['50']['dist'] * 100, $distances['50']['slope'], 
                $distances['200']['dist'] * 100, $distances['200']['slope']);
        } elseif (count($validSupports) === 1) {
            $score += 15;
            $p = $validSupports[0];
            $reasons[] = sprintf('Ana destege (SMA%s) %%%.1f yakinlikta ve destek egimi %%%.1f (%s) - tepki potansiyeli yüksek.', 
                $p, $distances[$p]['dist'] * 100, $distances[$p]['slope'], $distances[$p]['slope'] > 0 ? 'pozitif' : 'yatay/hafif negatif ama esik icinde');
        }

        foreach ($invalidSupports as $period) {
            $score -= 5;
            $reasons[] = sprintf('Fiyat SMA%s\'ye %%%.1f yakinlikta ama ortalama egimi %%%.1f (düsüs trendi) - destek gecersiz.', 
                $period, $distances[$period]['dist'] * 100, $distances[$period]['slope']);
        }

        // 2b. Yapisal Dusus Trendi Cezasi (kademeli)
        // Ceza degerleri kesin degil, baslangic tahmini - ileride backtest yaparken kalibre edilecek
        if ($sma50 > 0 && $sma200 > 0 && $lastClose < $sma50 && $lastClose < $sma200) {
            $distanceFromSma200 = ($sma200 - $lastClose) / $sma200;
            if ($distanceFromSma200 > 0.15) {
                $score -= 30;
                $reasons[] = sprintf('Fiyat SMA200den %%%.1f asagida (esik: %%15 uzeri) - derin yapisal dusus, kisa vadeli sinyaller (RSI/MACD) guvenilmez.', $distanceFromSma200 * 100);
            } elseif ($distanceFromSma200 > 0.07) {
                $score -= 18;
                $reasons[] = sprintf('Fiyat SMA200den %%%.1f asagida (esik: %%7-15 arasi) - yapisal dusus trendi, kisa vadeli sinyaller zayif teyit sayilmali.', $distanceFromSma200 * 100);
            } else {
                $score -= 10;
                $reasons[] = sprintf('Fiyat SMA200den %%%.1f asagida (esik: %%7 alti) - yapi henuz tam toparlanmamis.', $distanceFromSma200 * 100);
            }
        }

        // 3. RSI Yorumlaması
        $rsi = $this->number($technical['rsi14'] ?? null, 50);
        $rsiSlope = $this->number($technical['rsi14_slope'] ?? null);

        if ($rsi > 75) {
            $score -= 15;
            $reasons[] = sprintf('RSI %.1f (esik: 75 uzeri) - asiri alim bolgesinde, duzeltme riski var.', $rsi);
        } elseif ($rsi > 65) {
            $score -= 5;
            $reasons[] = sprintf('RSI %.1f (esik: 65-75 arasi) - isinma bolgesine girmis, dikkatli olunmali.', $rsi);
        } elseif ($rsi >= 30 && $rsi <= 45) {
            if ($rsiSlope > 0) { // Dibi görüp dönüyorsa
                $score += 15;
                $reasons[] = sprintf('RSI %.1f (esik: 30-45 arasi) ve egimi %.1f - asiri satimdan donuyor, toparlanma sinyali.', $rsi, $rsiSlope);
            } else { // Düşüyor veya yataysa
                $score += 5;
                $reasons[] = sprintf('RSI %.1f (esik: 30-45 arasi) ve egimi %.1f - asiri satim bolgesinde ama donus zayif.', $rsi, $rsiSlope);
            }
        } elseif ($rsi < 30) {
            $score += 5;
            $reasons[] = sprintf('RSI %.1f (esik: 30 alti) - dipte surunuyor, cok ucuz.', $rsi);
        } elseif ($rsi > 45 && $rsi <= 65) {
            $score += 5;
            $reasons[] = sprintf('RSI %.1f (esik: 45-65 arasi) - notr bolgede, ozel bir sinyal yok.', $rsi);
        }

        // 4. Hacim Teyidi
        $volumeRatio = $this->number($technical['volumeRatio20'] ?? null, 1);
        if ($volumeRatio >= 1.2) {
            $score += 10;
            $reasons[] = sprintf('Hacim, 20 gunluk ortalamanin %.2f kati (esik: 1.2 kati uzeri) - hacim teyidi var.', $volumeRatio);
        }

        // 5. MACD Momentum Teyidi
        $macd = is_array($technical['macd'] ?? null) ? $technical['macd'] : [];
        $histogram = $this->number($macd['histogram'] ?? null);
        if ($histogram > 0) {
            $score += 10; // Daha önce 5'ti, şimdi 10 (teyit olarak daha değerli)
            $reasons[] = sprintf('MACD momentumu pozitif (Histogram: %.3f).', $histogram);
        } elseif ($histogram < 0) {
            $score -= 5;
        }

        // 6. XU100 (Endeks) Yön Filtresi
        $xu100 = is_array($technical['xu100'] ?? null) ? $technical['xu100'] : [];
        $xu100_last = $this->number($xu100['lastClose'] ?? null);
        $xu100_sma50 = $this->number($xu100['sma']['50'] ?? null);
        $xu100_sma50Slope = $this->number($xu100['sma']['50_slope'] ?? null);
        
        if ($xu100_last > 0 && $xu100_sma50 > 0) {
            $xuDist = (($xu100_last - $xu100_sma50) / $xu100_sma50) * 100;
            if ($xu100_last < $xu100_sma50 && $xu100_sma50Slope < 0) {
                $score -= 10;
                $reasons[] = sprintf('Endeks (XU100) SMA50nin %%%.1f altinda ve egim negatif - sistemik risk yuksek.', abs($xuDist));
            } elseif ($xu100_last > $xu100_sma50 && $xu100_sma50Slope > 0) {
                $score += 5;
                $reasons[] = sprintf('Endeks (XU100) SMA50nin %%%.1f uzerinde ve egim pozitif - ruzgar arkada.', $xuDist);
            }
        }

        if ($stale) {
            $score -= 15;
            $reasons = array_slice($reasons, 0, 4);
            $reasons[] = 'Tarihsel veri stale; AI adayligina alinmadi.';
        }

        $hasBaseSetup = count($validSupports) > 0 || ($rsi >= 30 && $rsi <= 45);

        return [
            'score' => max(0, min(100, (int) round($score))),
            'status' => $stale ? OpportunityCandidate::STATUS_STALE : (!$hasBaseSetup ? OpportunityCandidate::STATUS_INELIGIBLE : OpportunityCandidate::STATUS_ELIGIBLE),
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
