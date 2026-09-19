<?php

namespace App\Console\Commands;

use App\Models\Backtest;
use App\Models\MarketData;
use App\Models\Stock;
use App\Services\BacktestEngine;
use Illuminate\Console\Command;

/**
 * Runs the current signal strategy over stored market data and prints a
 * verdict-style summary so the idea's profitability is testable from the CLI.
 */
class BacktestRun extends Command
{
    protected $signature = 'trader:backtest
        {--from= : Start date (Y-m-d); defaults to earliest stored bar}
        {--to= : End date (Y-m-d); defaults to latest stored bar}
        {--capital= : Starting capital; defaults to risk.capital config}
        {--min-score= : Override product.min_score}
        {--min-confirm= : Override product.min_reversal_confirmations (0 disables the gate)}
        {--sl= : Override risk.stop_loss_pct}
        {--t1= : Override risk.target1_pct}
        {--t3= : Override risk.target3_pct}
        {--name= : Backtest name (persisted)}
        {--no-persist : Do not write the result to the backtests table}
        {--trades : Print the full trade log}';

    protected $description = 'Backtest the current buy/sell strategy and report whether it works';

    public function handle(BacktestEngine $engine): int
    {
        $stocks = Stock::where('active', true)->where('in_watchlist', true)->orderBy('symbol')->get();

        if ($stocks->isEmpty()) {
            $this->error('No active watchlist stocks found.');

            return self::FAILURE;
        }

        $from = $this->option('from') ?? MarketData::min('trade_date');
        $to = $this->option('to') ?? MarketData::max('trade_date');

        if (! $from || ! $to || $from > $to) {
            $this->error('Invalid or empty date range — run `php artisan market:ingest` first.');

            return self::FAILURE;
        }

        $overrides = $this->buildOverrides();

        $this->info("Backtesting {$stocks->count()} stocks: {$stocks->pluck('symbol')->implode(', ')}");
        $this->line("Period: {$from} → {$to}");

        $bt = $engine->run(
            $stocks,
            $from,
            $to,
            array_filter([
                'name' => $this->option('name'),
                'capital' => $this->option('capital') ? (float) $this->option('capital') : null,
                'overrides' => $overrides,
                'persist' => ! $this->option('no-persist'),
            ], fn ($v) => $v !== null),
        );

        $this->newLine();
        $this->renderVerdict($bt);
        $this->newLine();
        $this->renderSummary($bt);

        $trades = $bt->meta['trades'] ?? [];
        if (count($trades) === 0) {
            $this->warn('No trades were generated — the strategy gates never fired in this window.');
            $this->warn('Try lowering --min-score or --min-confirm to loosen the entry filter.');
        } elseif ($this->option('trades')) {
            $this->newLine();
            $this->renderTrades($trades);
        }

        return self::SUCCESS;
    }

    /**
     * @return array<string, float|int>
     */
    protected function buildOverrides(): array
    {
        $map = [
            'min-score' => 'product.min_score',
            'min-confirm' => 'product.min_reversal_confirmations',
            'sl' => 'risk.stop_loss_pct',
            't1' => 'risk.target1_pct',
            't3' => 'risk.target3_pct',
        ];

        $overrides = [];
        foreach ($map as $flag => $key) {
            $value = $this->option($flag);
            if ($value !== null) {
                $overrides[$key] = (float) $value;
            }
        }

        return $overrides;
    }

    protected function renderVerdict(Backtest $bt): void
    {
        $net = (float) $bt->net_profit;
        $returnPct = (float) $bt->total_return_pct;
        $winRate = (float) $bt->win_rate;
        $pf = $bt->profit_factor;

        $this->line('=== VERDICT ===');

        if ($net > 0 && $winRate >= 50 && ($pf === null || (float) $pf >= 1.3)) {
            $this->info(sprintf('Profit = ₹%.0f (%+.2f%%)  WinRate = %.0f%%  ProfitFactor = %s',
                $net, $returnPct, $winRate, $pf === null ? 'INF' : round((float) $pf, 2)));
            $this->info('The strategy idea works on historical data.');
        } elseif ($net <= 0) {
            $this->error(sprintf('The strategy LOSES money: ₹%.0f (%+.2f%%) over the window.', $net, $returnPct));
            $this->line('The algo idea does not work as configured — tune gates/targets or rethink the edge.');
        } else {
            $this->warn(sprintf('Marginal: profit ₹%.0f but win rate %.0f%% / PF %s is weak.',
                $net, $winRate, $pf === null ? 'INF' : round((float) $pf, 2)));
        }
    }

    protected function renderSummary(Backtest $bt): void
    {
        $fmt = fn (float $v) => '₹'.number_format($v, 2);

        $rows = [
            ['Starting capital', $fmt((float) $bt->starting_capital)],
            ['Ending capital', $fmt((float) $bt->ending_capital)],
            ['Net profit', $fmt((float) $bt->net_profit)],
            ['Total return', number_format((float) $bt->total_return_pct, 2).'%'],
            ['CAGR', number_format((float) $bt->cagr, 2).'%'],
            ['Buy & hold return', number_format((float) $bt->buyhold_return_pct, 2).'%'],
            ['Buy & hold CAGR', number_format((float) $bt->buyhold_cagr, 2).'%'],
            ['Trades', (string) $bt->total_trades],
            ['Wins / Losses', "{$bt->wins} / {$bt->losses}"],
            ['Win rate', number_format((float) $bt->win_rate, 1).'%'],
            ['Avg profit', $fmt((float) $bt->avg_profit)],
            ['Avg loss', $fmt((float) $bt->avg_loss)],
            ['Profit factor', $bt->profit_factor === null ? 'INF' : number_format((float) $bt->profit_factor, 2)],
            ['Max drawdown', $fmt((float) $bt->max_drawdown).' ('.number_format((float) $bt->max_drawdown_pct, 1).'%)'],
            ['Max consecutive losses', (string) $bt->max_consecutive_losses],
            ['Largest gain', $fmt((float) $bt->largest_gain)],
            ['Largest loss', $fmt((float) $bt->largest_loss)],
            ['Avg holding days', number_format((float) $bt->avg_holding_days, 1)],
            ['Total costs', $fmt((float) $bt->total_costs)],
        ];

        $this->table(['Metric', 'Value'], $rows);

        if (is_array($bt->monthly_pnl) && count($bt->monthly_pnl) > 0) {
            $this->line('Monthly P&L (₹):');
            $this->table(
                ['Month', 'P&L'],
                array_map(fn ($month, $pnl) => [$month, '₹'.number_format((float) $pnl, 0)], array_keys($bt->monthly_pnl), $bt->monthly_pnl),
            );
        }
    }

    /**
     * @param  list<array<string, mixed>>  $trades
     */
    protected function renderTrades(array $trades): void
    {
        $this->line('Trade log:');
        $this->table(
            ['Symbol', 'Exit date', 'Reason', 'Entry', 'Exit', 'Qty', 'Net (₹)', 'Holding days'],
            array_map(fn ($t) => [
                $t['symbol'] ?? '',
                $t['exit_date'] ?? '',
                $t['reason'] ?? '',
                '₹'.number_format((float) ($t['entry'] ?? 0), 2),
                '₹'.number_format((float) ($t['exit'] ?? 0), 2),
                (string) ($t['qty'] ?? 0),
                '₹'.number_format((float) ($t['net'] ?? 0), 2),
                (string) ($t['holding_days'] ?? 0),
            ], $trades),
        );
    }
}
