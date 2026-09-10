<?php

namespace App\Services;

use App\Contracts\MarketData\MarketDataProvider;
use App\Models\MarketData;
use App\Models\Stock;
use Illuminate\Support\Collection;

/**
 * Orchestrates market-data ingestion and lookups for the engine, decoupled from
 * the concrete provider via MarketDataProvider.
 */
class MarketDataService
{
    public function __construct(
        protected MarketDataProvider $provider,
    ) {}

    /**
     * Fetch and persist historical data for a stock, returning the latest close.
     *
     * @return float|null latest close, or null if none stored
     */
    public function ingestHistory(Stock $stock, string $from, string $to): ?float
    {
        $rows = $this->provider->getHistory(
            $stock->yfinance_symbol ?? $stock->symbol,
            $from,
            $to,
        );

        $latest = null;
        foreach ($rows as $row) {
            MarketData::updateOrCreate(
                ['stock_id' => $stock->id, 'trade_date' => $row['date']],
                [
                    'open' => $row['open'],
                    'high' => $row['high'],
                    'low' => $row['low'],
                    'close' => $row['close'],
                    'volume' => $row['volume'],
                    'adjusted_close' => $row['close'],
                ],
            );
            $latest = (float) $row['close'];
        }

        return $latest;
    }

    /**
     * Get ordered daily close data for a stock since a start date.
     *
     * @return Collection<int, MarketData>
     */
    public function dailyData(Stock $stock, ?string $from = null): Collection
    {
        return MarketData::where('stock_id', $stock->id)
            ->when($from, fn ($q) => $q->where('trade_date', '>=', $from))
            ->orderBy('trade_date')
            ->get();
    }

    /**
     * Latest close for a stock (from stored data).
     */
    public function latestClose(Stock $stock): ?float
    {
        $last = MarketData::where('stock_id', $stock->id)->orderByDesc('trade_date')->first();

        return $last ? (float) $last->close : null;
    }

    /**
     * Live quote for a stock through the configured provider.
     *
     * @return array{open: float, high: float, low: float, close: float, volume: int}
     */
    public function quote(Stock $stock): array
    {
        return $this->provider->getQuote($stock->yfinance_symbol ?? $stock->symbol);
    }
}
