<?php

namespace App\Contracts\MarketData;

/**
 * Pluggable market-data provider. Today we ship the YahooFinanceProvider
 * (free NSE/BSE OHLCV via Yahoo). Live broker feeds (Kite, Upstox) can be
 * added later behind this same interface.
 */
interface MarketDataProvider
{
    /**
     * Fetch historical daily OHLCV for a symbol.
     *
     * @return array<int, array{date: string, open: float, high: float, low: float, close: float, volume: int}>
     */
    public function getHistory(string $symbol, string $from, string $to): array;

    /**
     * Fetch a single current quote.
     *
     * @return array{open: float, high: float, low: float, close: float, volume: int}
     */
    public function getQuote(string $symbol): array;
}
