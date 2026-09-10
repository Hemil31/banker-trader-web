<?php

namespace App\Console\Commands;

use App\Models\Stock;
use App\Models\User;
use App\Services\MarketDataService;
use App\Services\PaperTradingService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

class PaperTradingRun extends Command
{
    protected $signature = 'trader:paper
        {--asof= : Optional signal date (Y-m-d) — labels signals for a replay run}
        {--symbol=* : Restrict the watchlist to these stock IDs (repeatable)}';

    protected $description = 'Run one paper-trading session: scan the watchlist, enter new signals, monitor exits';

    public function handle(PaperTradingService $service, MarketDataService $marketData): int
    {
        $user = User::query()->orderBy('id')->first();
        if (! $user) {
            $this->error('No user exists. Create a user before running paper trading.');

            return self::FAILURE;
        }

        $stocks = $this->watchlist();

        if ($stocks->isEmpty()) {
            $this->warn('No active watchlist stocks. Run the market-data ingestion first.');

            return self::FAILURE;
        }

        $asof = $this->option('asof') ?: null;

        $this->info('Paper session for '.$stocks->count().' stocks'.($asof ? " as of {$asof}" : ''));

        $result = $service->runSession($stocks, $asof, $user);

        $portfolio = $result['portfolio'];

        $this->table(
            ['Signals', 'Entered', 'Blocked', 'Monitored', 'Exits'],
            [[
                $result['signals_generated'],
                $result['entered'],
                $result['blocked'],
                $result['monitored'],
                $result['exits'],
            ]],
        );

        $this->info(sprintf(
            'Portfolio: invested ₹%s | unrealized ₹%s | realized ₹%s | net equity ₹%s | available cash ₹%s',
            number_format($portfolio['invested'], 2),
            number_format($portfolio['unrealized'], 2),
            number_format($portfolio['realized'], 2),
            number_format($portfolio['net_equity'], 2),
            number_format((float) $result['account']->available_cash, 2),
        ));

        return self::SUCCESS;
    }

    /**
     * @return Collection<int, Stock>
     */
    protected function watchlist(): Collection
    {
        $ids = array_filter($this->option('symbol'));

        return $ids === []
            ? Stock::where('active', true)->where('in_watchlist', true)->get()
            : Stock::whereIn('id', $ids)->get();
    }
}
