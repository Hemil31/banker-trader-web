<?php

namespace App\Contracts\MarketData;

use Illuminate\Support\Facades\Http;

/**
 * Yahoo Finance chart API provider for NSE/BSE symbols (e.g. RELIANCE.NS).
 * Used by the data service to seed history; a live broker feed can replace it.
 */
class YahooFinanceProvider implements MarketDataProvider
{
    public function getHistory(string $symbol, string $from, string $to): array
    {
        $response = Http::timeout(20)
            ->retry(2, 500)
            ->withHeaders(['User-Agent' => 'Mozilla/5.0 (X11; Linux x86_64)'])
            ->get('https://query1.finance.yahoo.com/v8/finance/chart/'.rawurlencode($symbol), [
                'period1' => strtotime($from),
                'period2' => strtotime($to),
                'interval' => '1d',
                'events' => 'div,splits',
            ]);

        if ($response->failed()) {
            throw new \RuntimeException("Yahoo history request failed for {$symbol}");
        }

        $result = $response->json('chart.result.0');
        if (! $result) {
            return [];
        }

        $timestamps = $result['timestamp'] ?? [];
        $quote = $result['indicators']['quote'][0] ?? [];

        $rows = [];
        foreach ($timestamps as $i => $ts) {
            $open = $quote['open'][$i] ?? null;
            $close = $quote['close'][$i] ?? null;
            if ($open === null || $close === null) {
                continue;
            }

            $rows[] = [
                'date' => date('Y-m-d', $ts),
                'open' => (float) $open,
                'high' => (float) ($quote['high'][$i] ?? $close),
                'low' => (float) ($quote['low'][$i] ?? $close),
                'close' => (float) $close,
                'volume' => (int) ($quote['volume'][$i] ?? 0),
            ];
        }

        return $rows;
    }

    public function getQuote(string $symbol): array
    {
        $response = Http::timeout(20)
            ->withHeaders(['User-Agent' => 'Mozilla/5.0 (X11; Linux x86_64)'])
            ->get('https://query1.finance.yahoo.com/v8/finance/chart/'.rawurlencode($symbol), [
                'interval' => '1d',
                'range' => '1d',
            ]);

        if ($response->failed()) {
            throw new \RuntimeException("Yahoo quote request failed for {$symbol}");
        }

        $result = $response->json('chart.result.0');
        $quote = $result['indicators']['quote'][0] ?? [];
        $close = $quote['close'][0] ?? null;
        $open = $quote['open'][0] ?? $close;
        $high = $quote['high'][0] ?? $close;
        $low = $quote['low'][0] ?? $close;

        return [
            'open' => (float) ($open ?? 0),
            'high' => (float) ($high ?? 0),
            'low' => (float) ($low ?? 0),
            'close' => (float) ($close ?? 0),
            'volume' => (int) ($quote['volume'][0] ?? 0),
        ];
    }
}
