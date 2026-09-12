<?php

namespace App\Console\Commands;

use App\Models\Stock;
use App\Services\AutoTradingService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

class AutomationRun extends Command
{
    protected $signature = 'trader:auto
        {--account= : Only run this trading account id}
        {--symbol=* : Restrict the watchlist to these stock IDs (repeatable)}';

    protected $description = 'Run the automated trading loop for every enrolled account: news → scan → enter → monitor exits';

    public function handle(AutoTradingService $auto): int
    {
        $stocks = $this->watchlist();

        if ($stocks->isEmpty()) {
            $this->warn('No active watchlist stocks. Run the market-data ingestion first.');

            return self::FAILURE;
        }

        $accountId = $this->option('account');

        $results = $auto->run($stocks, $accountId ?: null);

        if ($results === []) {
            $this->warn('No enrolled accounts found (master_enabled + strategy_enabled). Nothing to automate.');

            return self::FAILURE;
        }

        $rows = [];

        foreach ($results as $result) {
            $rows[] = [
                $result['account']->id,
                $result['account']->user->username ?? $result['account']->user->email ?? '-',
                $result['broker'] ?? '-',
                $result['capital'] ?? '-',
                $result['signals_generated'] ?? '-',
                $result['entered'] ?? '-',
                $result['blocked'] ?? '0',
                $result['monitored'] ?? '-',
                $result['exits'] ?? '-',
                number_format($result['daily_realized'] ?? 0, 2),
                $result['error'] ?? 'ok',
            ];
        }

        $this->table(
            ['Account', 'User', 'Broker', 'Capital', 'Signals', 'Entered', 'Blocked', 'Monitored', 'Exits', '₹Today', 'Status'],
            $rows,
        );

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
