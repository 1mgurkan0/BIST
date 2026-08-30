<?php

namespace App\Service;

class TradingViewMarketService
{
    /**
     * @param string[] $symbols e.g. ['THYAO', 'ASELS']
     * @return array<string, array{price: float, change: float}>
     */
    public function fetchBulkPrices(array $symbols): array
    {
        if (empty($symbols)) {
            return [];
        }

        $tickers = array_map(fn($s) => 'BIST:' . strtoupper($s), $symbols);

        $payload = [
            'symbols' => ['tickers' => array_values($tickers)],
            'columns' => ['close', 'change']
        ];

        $ch = curl_init('https://scanner.tradingview.com/turkey/scan');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || !$response) {
            return [];
        }

        $data = json_decode((string) $response, true);
        if (!isset($data['data']) || !is_array($data['data'])) {
            return [];
        }

        $results = [];
        foreach ($data['data'] as $item) {
            if (isset($item['s'], $item['d'][0], $item['d'][1])) {
                $symbol = str_replace('BIST:', '', $item['s']);
                $results[$symbol] = [
                    'price' => (float) $item['d'][0],
                    'change' => (float) $item['d'][1],
                ];
            }
        }

        return $results;
    }

    public function fetchFundamentals(array $symbols): array
    {
        if (empty($symbols)) return [];
        $tickers = array_map(fn($s) => 'BIST:' . strtoupper($s), $symbols);
        $payload = [
            'symbols' => ['tickers' => array_values($tickers)],
            'columns' => ['market_cap_basic', 'price_earnings_ttm', 'price_book_ratio', 'earnings_per_share_basic_ttm']
        ];

        $ch = curl_init('https://scanner.tradingview.com/turkey/scan');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || !$response) return [];
        $data = json_decode((string) $response, true);
        if (!isset($data['data']) || !is_array($data['data'])) return [];

        $results = [];
        foreach ($data['data'] as $item) {
            if (isset($item['s'])) {
                $symbol = str_replace('BIST:', '', $item['s']);
                $results[$symbol] = [
                    'market_cap' => $item['d'][0] ?? null,
                    'pe_ratio' => isset($item['d'][1]) ? round($item['d'][1], 2) : null,
                    'price_to_book' => isset($item['d'][2]) ? round($item['d'][2], 2) : null,
                    'eps' => isset($item['d'][3]) ? round($item['d'][3], 2) : null,
                ];
            }
        }
        return $results;
    }
}

