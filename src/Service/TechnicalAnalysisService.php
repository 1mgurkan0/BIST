<?php

namespace App\Service;

class TechnicalAnalysisService
{
    /**
     * @param array<int, array<string, mixed>> $bars
     * @return array<string, mixed>
     */
    public function analyze(array $bars): array
    {
        $bars = array_values(array_filter($bars, static fn(array $bar): bool => is_numeric($bar['close'] ?? null)));
        $closes = array_map(static fn(array $bar): float => (float) $bar['close'], $bars);
        $count = count($closes);

        if ($count < 20) {
            return [
                'status' => 'insufficient_history',
                'bars' => $count,
            ];
        }

        $lastClose = $closes[$count - 1];
        $sma20 = $this->sma($closes, 20);
        $sma50 = $this->sma($closes, 50);
        $sma200 = $this->sma($closes, 200);
        $rsi14 = $this->rsi($closes, 14);
        $macd = $this->macd($closes);
        $recent20 = array_slice($bars, -20);
        $recent60 = array_slice($bars, -60);
        $high52 = $this->maxNumeric($bars, 'high');
        $low52 = $this->minNumeric($bars, 'low');
        $support20 = $this->minNumeric($recent20, 'low');
        $resistance20 = $this->maxNumeric($recent20, 'high');
        $support60 = $this->minNumeric($recent60, 'low');
        $resistance60 = $this->maxNumeric($recent60, 'high');
        $volumeRatio = $this->volumeRatio($bars, 20);
        $volatility20 = $this->annualizedVolatility($closes, 20);
        $atr14 = $this->atr($bars, 14);

        $bullishSignals = 0;
        $bearishSignals = 0;
        foreach ([$sma20, $sma50, $sma200] as $sma) {
            if ($sma === null) {
                continue;
            }
            $lastClose >= $sma ? $bullishSignals++ : $bearishSignals++;
        }
        if ($macd['histogram'] !== null) {
            $macd['histogram'] >= 0 ? $bullishSignals++ : $bearishSignals++;
        }
        if ($rsi14 !== null) {
            if ($rsi14 >= 55 && $rsi14 <= 75) {
                $bullishSignals++;
            } elseif ($rsi14 < 45 || $rsi14 > 80) {
                $bearishSignals++;
            }
        }

        $trend = $bullishSignals >= $bearishSignals + 2
            ? 'pozitif'
            : ($bearishSignals >= $bullishSignals + 2 ? 'negatif' : 'notr');

        // Calculate slopes (difference over the last 3-45 days)
        $rsi14_prev = count($closes) > 17 ? $this->rsi(array_slice($closes, 0, -3), 14) : $rsi14;
        $rsi14_slope = $rsi14 !== null && $rsi14_prev !== null ? $rsi14 - $rsi14_prev : 0;
        
        $sma20_prev = count($closes) > 25 ? $this->sma(array_slice($closes, 0, -5), 20) : $sma20;
        $sma50_prev = count($closes) > 65 ? $this->sma(array_slice($closes, 0, -15), 50) : $sma50;
        $sma200_prev = count($closes) > 245 ? $this->sma(array_slice($closes, 0, -45), 200) : $sma200;

        return [
            'status' => 'ok',
            'bars' => $count,
            'firstDate' => $bars[0]['date'] ?? null,
            'lastDate' => $bars[$count - 1]['date'] ?? null,
            'lastClose' => $this->round($lastClose),
            'trend' => $trend,
            'returns' => [
                '1d' => $this->returnForPeriod($closes, 1),
                '1w' => $this->returnForPeriod($closes, 5),
                '1m' => $this->returnForPeriod($closes, 21),
                '3m' => $this->returnForPeriod($closes, 63),
                '6m' => $this->returnForPeriod($closes, 126),
                '1y' => $this->returnForPeriod($closes, min(252, $count - 1)),
            ],
            'sma' => [
                '20' => $this->round($sma20),
                '50' => $this->round($sma50),
                '200' => $this->round($sma200),
                '20_slope' => $sma20 !== null && $sma20_prev !== null ? $this->round((($sma20 / $sma20_prev) - 1) * 100) : 0,
                '50_slope' => $sma50 !== null && $sma50_prev !== null ? $this->round((($sma50 / $sma50_prev) - 1) * 100) : 0,
                '200_slope' => $sma200 !== null && $sma200_prev !== null ? $this->round((($sma200 / $sma200_prev) - 1) * 100) : 0,
            ],
            'rsi14' => $this->round($rsi14),
            'rsi14_slope' => $this->round($rsi14_slope),
            'macd' => [
                'value' => $this->round($macd['value']),
                'signal' => $this->round($macd['signal']),
                'histogram' => $this->round($macd['histogram']),
            ],
            'atr14' => $this->round($atr14),
            'volatility20' => $this->round($volatility20),
            'volumeRatio20' => $this->round($volumeRatio),
            'levels' => [
                'support20' => $this->round($support20),
                'resistance20' => $this->round($resistance20),
                'support60' => $this->round($support60),
                'resistance60' => $this->round($resistance60),
                'low52w' => $this->round($low52),
                'high52w' => $this->round($high52),
                'distanceToHigh52w' => $high52 && $high52 > 0 ? $this->round((($lastClose / $high52) - 1) * 100) : null,
                'distanceToLow52w' => $low52 && $low52 > 0 ? $this->round((($lastClose / $low52) - 1) * 100) : null,
            ],
        ];
    }

    /** @param float[] $values */
    private function sma(array $values, int $period): ?float
    {
        if (count($values) < $period) {
            return null;
        }

        return array_sum(array_slice($values, -$period)) / $period;
    }

    /** @param float[] $values */
    private function emaSeries(array $values, int $period): array
    {
        if (count($values) < $period) {
            return [];
        }

        $multiplier = 2 / ($period + 1);
        $ema = array_sum(array_slice($values, 0, $period)) / $period;
        $series = array_fill(0, $period - 1, null);
        $series[] = $ema;

        for ($i = $period; $i < count($values); $i++) {
            $ema = (($values[$i] - $ema) * $multiplier) + $ema;
            $series[] = $ema;
        }

        return $series;
    }

    /** @param float[] $values */
    private function macd(array $values): array
    {
        $ema12 = $this->emaSeries($values, 12);
        $ema26 = $this->emaSeries($values, 26);
        if (empty($ema26)) {
            return ['value' => null, 'signal' => null, 'histogram' => null];
        }

        $macdSeries = [];
        foreach ($values as $index => $_) {
            if (is_numeric($ema12[$index] ?? null) && is_numeric($ema26[$index] ?? null)) {
                $macdSeries[] = (float) $ema12[$index] - (float) $ema26[$index];
            }
        }

        $signalSeries = $this->emaSeries($macdSeries, 9);
        $value = empty($macdSeries) ? null : $macdSeries[array_key_last($macdSeries)];
        $signal = empty($signalSeries) ? null : $signalSeries[array_key_last($signalSeries)];

        return [
            'value' => $value,
            'signal' => is_numeric($signal) ? (float) $signal : null,
            'histogram' => is_numeric($value) && is_numeric($signal) ? $value - $signal : null,
        ];
    }

    /** @param float[] $values */
    private function rsi(array $values, int $period): ?float
    {
        if (count($values) <= $period) {
            return null;
        }

        $gains = 0.0;
        $losses = 0.0;
        for ($i = count($values) - $period; $i < count($values); $i++) {
            $change = $values[$i] - $values[$i - 1];
            $change >= 0 ? $gains += $change : $losses += abs($change);
        }

        if ($losses === 0.0) {
            return 100.0;
        }

        $rs = ($gains / $period) / ($losses / $period);
        return 100 - (100 / (1 + $rs));
    }

    /** @param float[] $values */
    private function returnForPeriod(array $values, int $period): ?float
    {
        $count = count($values);
        if ($period < 1 || $count <= $period) {
            return null;
        }

        $start = $values[$count - 1 - $period];
        return $start > 0 ? (($values[$count - 1] / $start) - 1) * 100 : null;
    }

    /** @param float[] $values */
    private function annualizedVolatility(array $values, int $period): ?float
    {
        if (count($values) <= $period) {
            return null;
        }

        $slice = array_slice($values, -($period + 1));
        $returns = [];
        for ($i = 1; $i < count($slice); $i++) {
            if ($slice[$i - 1] > 0) {
                $returns[] = log($slice[$i] / $slice[$i - 1]);
            }
        }

        if (count($returns) < 2) {
            return null;
        }

        $mean = array_sum($returns) / count($returns);
        $variance = array_sum(array_map(static fn(float $value): float => ($value - $mean) ** 2, $returns)) / (count($returns) - 1);
        return sqrt($variance) * sqrt(252) * 100;
    }

    /** @param array<int, array<string, mixed>> $bars */
    private function atr(array $bars, int $period): ?float
    {
        if (count($bars) <= $period) {
            return null;
        }

        $trueRanges = [];
        for ($i = count($bars) - $period; $i < count($bars); $i++) {
            $high = $bars[$i]['high'] ?? null;
            $low = $bars[$i]['low'] ?? null;
            $previousClose = $bars[$i - 1]['close'] ?? null;
            if (!is_numeric($high) || !is_numeric($low) || !is_numeric($previousClose)) {
                continue;
            }

            $trueRanges[] = max(
                (float) $high - (float) $low,
                abs((float) $high - (float) $previousClose),
                abs((float) $low - (float) $previousClose)
            );
        }

        return empty($trueRanges) ? null : array_sum($trueRanges) / count($trueRanges);
    }

    /** @param array<int, array<string, mixed>> $bars */
    private function volumeRatio(array $bars, int $period): ?float
    {
        $volumes = array_values(array_filter(array_map(
            static fn(array $bar): ?float => is_numeric($bar['volume'] ?? null) ? (float) $bar['volume'] : null,
            array_slice($bars, -($period + 1))
        ), static fn(?float $value): bool => $value !== null && $value > 0));

        if (count($volumes) < 2) {
            return null;
        }

        $last = array_pop($volumes);
        $average = array_sum($volumes) / count($volumes);
        return $average > 0 ? $last / $average : null;
    }

    /** @param array<int, array<string, mixed>> $bars */
    private function maxNumeric(array $bars, string $field): ?float
    {
        $values = array_values(array_filter(array_map(
            static fn(array $bar): ?float => is_numeric($bar[$field] ?? null) ? (float) $bar[$field] : null,
            $bars
        ), static fn(?float $value): bool => $value !== null));

        return empty($values) ? null : max($values);
    }

    /** @param array<int, array<string, mixed>> $bars */
    private function minNumeric(array $bars, string $field): ?float
    {
        $values = array_values(array_filter(array_map(
            static fn(array $bar): ?float => is_numeric($bar[$field] ?? null) ? (float) $bar[$field] : null,
            $bars
        ), static fn(?float $value): bool => $value !== null));

        return empty($values) ? null : min($values);
    }

    private function round(?float $value): ?float
    {
        return $value === null ? null : round($value, 2);
    }
}

