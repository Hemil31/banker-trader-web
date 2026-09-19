<?php

namespace App\Console\Commands;

use App\Models\MarketData;
use App\Models\Stock;
use App\Services\DeliveryScalper;
use App\Services\IndicatorCalculator;
use Illuminate\Console\Command;

/**
 * Continuous-rotation delivery scalper backtest.
 *
 * Scans all watchlist stocks for momentum entry signals, takes 1–2% profit,
 * and immediately rotates into the next opportunity.
 *
 * Usage:
 *   php artisan trader:scalp                      # full watchlist
 *   php artisan trader:scalp --tp=2 --sl=1 --hold=7   # custom targets
 *   php artisan trader:scalp --trades            # print full trade log
 */
class ScalpRun extends Command
{
    protected $signature = 'trader:scalp
        {--from= : Start date (Y-m-d)}
        {--to= : End date (Y-m-d)}
        {--capital= : Starting capital (default 100000)}
        {--tp= : Take-profit % (default 1.5)}
        {--sl= : Stop-loss % (default 1.0)}
        {--hold= : Max hold in trading days (default 5)}
        {--max-per= : Max % of capital per stock (default 20)}
        {--max-exp= : Max % total exposure (default 70)}
        {--windows= : Lookback windows (comma list) for buy/sell idea, e.g. 5,7,12}
        {--symbol= : Restrict to a single symbol (e.g. SBIN)}
        {--trades : Print the full trade log}';

    protected $description = 'Backtest the continuous-rotation delivery scalper (1-2% targets)';

    public function handle(DeliveryScalper $scalper, IndicatorCalculator $calc): int
    {
        $symbol = (string) ($this->option('symbol') ?? '');

        $stocks = $symbol !== ''
            ? Stock::where('active', true)->where('symbol', strtoupper($symbol))->get()
            : Stock::where('active', true)->where('in_watchlist', true)->orderBy('symbol')->get();

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

        $capital = (float) ($this->option('capital') ?? 100000);
        $tp = (float) ($this->option('tp') ?? 1.5);
        $sl = (float) ($this->option('sl') ?? 1.0);
        $hold = (int) ($this->option('hold') ?? 5);
        $maxPer = (float) ($this->option('max-per') ?? 20);
        $maxExp = (float) ($this->option('max-exp') ?? 70);
        $windows = array_map('intval', array_filter(explode(',', (string) ($this->option('windows') ?? '5,7,12'))));

        $this->info('Delivery Scalper — continuous rotation backtest');
        $this->line("Stocks: {$stocks->pluck('symbol')->implode(', ')}");
        $this->line("Period: {$from} → {$to}  |  Capital: ₹".number_format($capital));
        $this->line("TP: +{$tp}%  |  SL: -{$sl}%  |  Max hold: {$hold}d  |  Per-stock: {$maxPer}%  |  Exposure: {$maxExp}%");
        $this->line('Lookback windows (buy/sell idea): '.implode(', ', $windows).' days');

        $result = $scalper->run(
            $stocks,
            $from,
            $to,
            $capital,
            ['tp_pct' => $tp, 'sl_pct' => $sl, 'max_hold_days' => $hold, 'max_pct_per_stock' => $maxPer, 'max_exposure_pct' => $maxExp, 'lookback_windows' => $windows],
        );

        $s = $result['stats'];

        $this->newLine();
        $this->renderVerdict($s, $capital);
        $this->newLine();
        $this->renderSummary($s, $capital, $tp, $sl, $hold);
        $this->newLine();
        $this->renderExits($s['exit_reasons'] ?? []);
        $this->newLine();
        $this->renderMonthlyTable($result['monthlyPnl']);

        if ($this->option('trades')) {
            $this->newLine();
            $this->renderTrades($result['trades']);
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $s
     */
    protected function renderVerdict(array $s, float $capital): void
    {
        $net = (float) $s['net_profit'];
        $ret = (float) $s['total_return_pct'];
        $wr = (float) $s['win_rate'];
        $pf = $s['profit_factor'];

        $this->line('=== VERDICT ===');

        if ($net > 0 && $wr >= 50 && ($pf === null || (float) $pf >= 1.3)) {
            $this->info(sprintf('Profit = ₹%.0f (%+.2f%%)  WinRate = %.0f%%  ProfitFactor = %s',
                $net, $ret, $wr, $pf === null ? 'INF' : round((float) $pf, 2)));
            $this->info('The delivery scalper makes money.');
        } elseif ($net <= 0) {
            $this->error(sprintf('Scalper LOSES: ₹%.0f (%+.2f%%)', $net, $ret));
        } else {
            $this->warn(sprintf('Marginal: profit ₹%.0f, win rate %.0f%%, PF %s',
                $net, $wr, $pf === null ? 'INF' : round((float) $pf, 2)));
        }
    }

    /**
     * @param  array<string, mixed>  $s
     */
    protected function renderSummary(array $s, float $capital, float $tp, float $sl, int $hold): void
    {
        $fmt = fn (float $v) => '₹'.number_format($v, 2);

        $this->table(['Metric', 'Value'], [
            ['Starting capital', $fmt($capital)],
            ['Ending capital', $fmt((float) $s['ending_capital'])],
            ['Net profit', $fmt((float) $s['net_profit'])],
            ['Total return', number_format((float) $s['total_return_pct'], 2).'%'],
            ['CAGR', number_format((float) $s['cagr'], 2).'%'],
            ['Trades', (string) $s['total_trades']],
            ['Wins / Losses', "{$s['wins']} / {$s['losses']}"],
            ['Win rate', number_format((float) $s['win_rate'], 1).'%'],
            ['Avg profit per win', $fmt((float) $s['avg_profit'])],
            ['Avg loss per loss', $fmt((float) $s['avg_loss'])],
            ['Profit factor', $s['profit_factor'] === null ? 'INF' : number_format((float) $s['profit_factor'], 2)],
            ['Max drawdown', $fmt((float) $s['max_drawdown']).' ('.number_format((float) $s['max_drawdown_pct'], 1).'%)'],
            ['Max consecutive losses', (string) $s['max_consecutive_losses']],
            ['Largest gain', $fmt((float) $s['largest_gain'])],
            ['Largest loss', $fmt((float) $s['largest_loss'])],
            ['Avg holding days', number_format((float) $s['avg_holding_days'], 1).'d'],
            ['Target', "TP +{$tp}% / SL -{$sl}% / max {$hold}d"],
        ]);
    }

    /**
     * @param  array<string, int>  $reasons
     */
    protected function renderExits(array $reasons): void
    {
        if ($reasons === []) {
            return;
        }
        $this->line('Exit breakdown:');
        $total = array_sum($reasons);
        $this->table(
            ['Reason', 'Count', '%'],
            array_map(fn ($r, $c) => [$r, (string) $c, number_format($c / max($total, 1) * 100, 1).'%'], array_keys($reasons), $reasons),
        );
    }

    /**
     * @param  array<string, float>  $monthlyPnl
     */
    protected function renderMonthlyTable(array $monthlyPnl): void
    {
        if ($monthlyPnl === []) {
            return;
        }
        $this->line('Monthly P&L (₹):');
        $this->table(
            ['Month', 'P&L'],
            array_map(fn ($m, $p) => [$m, '₹'.number_format((float) $p, 0)], array_keys($monthlyPnl), $monthlyPnl),
        );
    }

    /**
     * @param  list<array<string, mixed>>  $trades
     */
    protected function renderTrades(array $trades): void
    {
        $this->line('Trade log:');
        $this->table(
            ['Symbol', 'Entry date', 'Exit date', 'Entry', 'Exit', 'Qty', 'P&L (₹)', 'Days', 'Reason'],
            array_map(fn ($t) => [
                $t['symbol'],
                $t['entry_date'],
                $t['exit_date'],
                '₹'.number_format((float) $t['entry'], 2),
                '₹'.number_format((float) $t['exit'], 2),
                (string) $t['qty'],
                '₹'.number_format((float) $t['pnl'], 2),
                (string) $t['holding_days'],
                $t['reason'],
            ], $trades),
        );
    }
}
