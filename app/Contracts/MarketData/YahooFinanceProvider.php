<?php

namespace App\Contracts\MarketData;

use Illuminate\Support\Facades\Http;

/**
 * Yahoo Finance chart API provider for NSE/BSE symbols (e.g. RELIANCE.NS).
 * Used by the data service to seed history; a live broker feed can replace it.
 *
 * Yahoo throttles this endpoint aggressively (HTTP 429), so each request
 * alternates between two hosts and retries with exponential backoff.
 */
class YahooFinanceProvider implements MarketDataProvider
{
    private const HOSTS = [
        'https://query2.finance.yahoo.com/v8/finance/chart/',
        'https://query1.finance.yahoo.com/v8/finance/chart/',
    ];

    /**
     * @param  array<string, string|int>  $query
     * @return array<string, mixed>
     */
    private function fetch(string $symbol, array $query): array
    {
        $attempts = 0;
        $maxAttempts = 4;

        while ($attempts < $maxAttempts) {
            $host = self::HOSTS[$attempts % count(self::HOSTS)];

            $response = Http::timeout(25)
                ->withHeaders(['User-Agent' => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 Chrome/120'])
                ->get($host.rawurlencode($symbol), $query);

            if ($response->successful()) {
                return $response->json() ?? [];
            }

            $attempts++;
            if ($attempts < $maxAttempts) {
                sleep(4 * $attempts); // backoff: 4s, 8s, 12s
            }
        }

        throw new \RuntimeException("Yahoo request failed for {$symbol}");
    }

    public function getHistory(string $symbol, string $from, string $to): array
    {
        $json = $this->fetch($symbol, [
            'period1' => (int) strtotime($from),
            'period2' => (int) strtotime($to),
            'interval' => '1d',
            'events' => 'div,splits',
        ]);

        $result = $json['chart']['result'][0] ?? null;
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
        $json = $this->fetch($symbol, ['interval' => '1d', 'range' => '1d']);

        $result = $json['chart']['result'][0] ?? null;
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
