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
        $score = 40.0;
        $reasons = [];

        $trend = (string) ($technical['trend'] ?? 'notr');
        if ($trend === 'pozitif') {
            $score += 10;
            $reasons[] = 'Teknik trend pozitif.';
        } elseif ($trend === 'negatif') {
            $score -= 12;
            $reasons[] = 'Teknik trend negatif.';
        }

        $returns = is_array($technical['returns'] ?? null) ? $technical['returns'] : [];
        $oneWeek = $this->number($returns['1w'] ?? null);
        $oneMonth = $this->number($returns['1m'] ?? null);
        $threeMonth = $this->number($returns['3m'] ?? null);
        $score += $this->bounded($oneWeek, -4, 4);
        $score += $this->bounded($oneMonth / 2, -6, 6);
        $score += $this->bounded($threeMonth / 4, -7, 8);

        if ($oneMonth > 3 && $threeMonth > 8) {
            $reasons[] = 'Bir ve uc aylik momentum pozitif.';
        } elseif ($oneMonth < -8) {
            $reasons[] = 'Aylik momentum zayif.';
        }

        $rsi = $this->number($technical['rsi14'] ?? null, 50);
        if ($rsi >= 50 && $rsi <= 68) {
            $score += 6;
            $reasons[] = 'RSI guclu fakat asiri alimda degil.';
        } elseif ($rsi > 78) {
            $score -= 10;
            $reasons[] = 'RSI asiri alim riskine yakin.';
        } elseif ($rsi < 35) {
            $score -= 6;
            $reasons[] = 'RSI zayif momentum gosteriyor.';
        }

        $macd = is_array($technical['macd'] ?? null) ? $technical['macd'] : [];
        $histogram = $this->number($macd['histogram'] ?? null);
        if ($histogram > 0) {
            $score += 5;
            $reasons[] = 'MACD momentumu pozitif.';
        } elseif ($histogram < 0) {
            $score -= 5;
        }

        $lastClose = $this->number($technical['lastClose'] ?? null);
        $sma = is_array($technical['sma'] ?? null) ? $technical['sma'] : [];
        foreach (['20' => 2, '50' => 3, '200' => 4] as $period => $weight) {
            $average = $this->number($sma[$period] ?? null);
            if ($average > 0) {
                $score += $lastClose >= $average ? $weight : -$weight;
            }
        }

        $volumeRatio = $this->number($technical['volumeRatio20'] ?? null, 1);
        if ($volumeRatio >= 1.2 && $volumeRatio <= 3.0) {
            $score += 4;
            $reasons[] = 'Hacim ortalamanin uzerinde.';
        } elseif ($volumeRatio > 4) {
            $score -= 3;
            $reasons[] = 'Olagandisi hacim teyit gerektiriyor.';
        }

        $volatility = $this->number($technical['volatility20'] ?? null);
        if ($volatility > 65) {
            $score -= 9;
            $reasons[] = 'Kisa vadeli oynaklik cok yuksek.';
        } elseif ($volatility > 45) {
            $score -= 4;
        }

        if ($stale) {
            $score -= 15;
            $reasons = array_slice($reasons, 0, 4);
            $reasons[] = 'Tarihsel veri stale; AI adayligina alinmadi.';
        }

        return [
            'score' => max(0, min(100, (int) round($score))),
            'status' => $stale ? 'stale' : 'eligible',
            'reasons' => array_slice($reasons, 0, 5),
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
