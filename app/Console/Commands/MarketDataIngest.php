<?php

namespace App\Console\Commands;

use App\Models\Stock;
use App\Services\MarketDataService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Loads historical Yahoo Finance OHLCV bars for the watchlist so signal
 * scanning and paper trading work on a fresh database.
 */
class MarketDataIngest extends Command
{
    protected $signature = 'market:ingest
        {--from= : Start date (Y-m-d); defaults to 2 years ago}
        {--to= : End date (Y-m-d); defaults to today}
        {--symbol=* : Restrict to these stock IDs (repeatable)}';

    protected $description = 'Fetch historical OHLCV bars from Yahoo Finance for watchlist stocks';

    public function handle(MarketDataService $marketData): int
    {
        $stocks = $this->targets();

        if ($stocks->isEmpty()) {
            $this->error('No active watchlist stocks found. Run `php artisan db:seed` first.');

            return self::FAILURE;
        }

        $from = $this->option('from') ?: now()->subYears(2)->toDateString();
        $to = $this->option('to') ?: today()->toDateString();

        $this->info("Ingesting {$stocks->count()} stocks {$from} → {$to}");

        $rows = 0;

        foreach ($stocks as $stock) {
            try {
                $latest = $marketData->ingestHistory($stock, $from, $to);
                $count = $latest !== null ? $stock->marketData()->count() : 0;
                $rows += $stock->marketData()->count();
                $this->line("  ✓ {$stock->symbol} — {$count} bars".($latest !== null ? " (latest close ₹{$latest})" : ''));
            } catch (\Throwable $e) {
                $this->error("  ✗ {$stock->symbol} — {$e->getMessage()}");
            }
        }

        $this->info("Done. {$rows} bars stored.");

        return self::SUCCESS;
    }

    /**
     * @return Collection<int, Stock>
     */
    protected function targets(): Collection
    {
        $ids = array_filter($this->option('symbol'));

        return $ids === []
            ? Stock::where('active', true)->where('in_watchlist', true)->get()
            : Stock::whereIn('id', $ids)->get();
    }
}
