<?php

namespace App\Console\Commands;

use App\Models\MarketData;
use App\Models\Stock;
use App\Services\BacktestEngine;
use App\Services\IndicatorCalculator;
use Illuminate\Console\Command;

/**
 * Per-stock trend + strategy analysis: shows which stocks are trending (market
 * data stats) and which ones the buy/sell strategy actually profits on
 * (per-stock backtest). Use it to pick the watchlist, not just to judge the algo.
 */
class ResearchRun extends Command
{
    protected $signature = 'trader:research
        {--from= : Start date (Y-m-d); defaults to earliest stored bar}
        {--to= : End date (Y-m-d); defaults to latest stored bar}';

    protected $description = 'Per-stock trend analysis + per-stock backtest of the strategy';

    public function handle(BacktestEngine $engine, IndicatorCalculator $calc): int
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

        $trendRows = [];
        $btRows = [];
        $works = 0;

        foreach ($stocks as $stock) {
            $trendRows[] = $this->trendStats($calc, $stock, $to);

            $bt = $engine->run([$stock], $from, $to, ['persist' => false]);
            $net = (float) $bt->net_profit;
            $pf = $bt->profit_factor === null ? null : (float) $bt->profit_factor;
            $good = $net > 0 && (float) $bt->win_rate >= 50 && ($pf === null || $pf >= 1.1);
            if ($good) {
                $works++;
            }

            $btRows[] = [
                'symbol' => $stock->symbol,
                'country' => $good ? 'WORKING' : ($net <= 0 ? 'losing' : 'marginal'),
                'net' => $net,
                'ret_pct' => (float) $bt->total_return_pct,
                'win_rate' => (float) $bt->win_rate,
                'trades' => (int) $bt->total_trades,
                'pf' => $bt->profit_factor === null ? INF : (float) $bt->profit_factor,
                'dd_pct' => (float) $bt->max_drawdown_pct,
                'holding' => (float) $bt->avg_holding_days,
                'costs' => (float) $bt->total_costs,
            ];
        }

        $this->info("Market data horizon: {$from} → {$to}\n");

        $this->line('=== 1. WHICH STOCKS ARE TRENDING (current picture) ===');
        $this->renderTrendTable($trendRows);

        $this->newLine();
        $this->line('=== 2. WHERE THE STRATEGY ACTUALLY MAKES MONEY (2y per-stock backtest) ===');
        $this->renderBacktestTable($btRows);

        $this->newLine();
        if ($works === 0) {
            $this->warn('No stock profited with the default strategy. The bear-market pullback idea is weak.');
        } else {
            $this->info("Strategy works on {$works}/".count($stocks).' stocks. Filter the watchlist to just those symbols and re-run `trader:backtest`.');
        }
        $this->line('Tip: a stock whose 1y return is positive AND trades profitably is both trending and carryable.');

        return self::SUCCESS;
    }

    /**
     * @return array<string, mixed>
     */
    protected function trendStats(IndicatorCalculator $calc, Stock $stock, string $asOf): array
    {
        $bars = MarketData::where('stock_id', $stock->id)
            ->where('trade_date', '<=', $asOf)
            ->orderBy('trade_date')
            ->get();

        $closes = $bars->pluck('close')->map(fn ($v) => (float) $v);
        $ind = $calc->compute($bars);
        $last = (float) ($closes->last() ?? 0);
        $n = $closes->count();

        $pct = function (int $lb) use ($closes, $n): ?float {
            if ($n <= $lb) {
                return null;
            }
            $start = $closes[$n - 1 - $lb];
            $last = $closes[$n - 1];

            return $start > 0 ? ($last / $start - 1) * 100 : null;
        };

        return [
            'symbol' => $stock->symbol,
            'last' => $last,
            'ret_1m' => $pct(21),
            'ret_3m' => $pct(63),
            'ret_6m' => $pct(126),
            'ret_1y' => $pct(252),
            'ret_2y' => $n > 1 && $closes->first() > 0 ? ($last / $closes->first() - 1) * 100 : null,
            'rsi' => $ind['rsi14'],
            'atr_pct' => $ind['atr14'] && $last > 0 ? (float) $ind['atr14'] / $last * 100 : null,
            'price_vs_ma50' => $ind['ma50'] && $ind['ma50'] > 0 ? ($last / (float) $ind['ma50'] - 1) * 100 : null,
            'off_20d_high' => $ind['dist_from_20d_high'],
            'avg_vol' => $ind['avg_volume_20d'],
            'vol_ratio' => $ind['volume_ratio'],
            'trend' => $this->trendTag($pct(252), $pct(63)),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    protected function renderTrendTable(array $rows): void
    {
        $this->table(
            ['SYMBOL', 'Price', '1M', '3M', '6M', '1Y', '2Y', 'RSI', 'ATR%', 'off 20dhi', 'Vol(x20d)', 'Trend'],
            array_map(function ($r) {
                $v = fn (?float $x, string $suf = '%') => $x === null ? '-' : number_format($x, 1).$suf;

                return [
                    $r['symbol'],
                    '₹'.number_format($r['last'], 1),
                    $v($r['ret_1m']),
                    $v($r['ret_3m']),
                    $v($r['ret_6m']),
                    $v($r['ret_1y']),
                    $v($r['ret_2y']),
                    $r['rsi'] === null ? '-' : number_format((float) $r['rsi'], 0),
                    $v($r['atr_pct']),
                    $v($r['off_20d_high']),
                    $r['vol_ratio'] === null ? '-' : number_format((float) $r['vol_ratio'], 2),
                    $r['trend'],
                ];
            }, $rows),
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    protected function renderBacktestTable(array $rows): void
    {
        $rows = collect($rows)->sortByDesc('net')->values();

        $this->table(
            ['SYMBOL', 'Net (₹)', 'Return', 'Win%', 'Trades', 'PF', 'MaxDD', 'Holding', 'Verdict'],
            $rows->map(fn ($r) => [
                $r['symbol'],
                '₹'.number_format($r['net'], 0),
                number_format($r['ret_pct'], 2).'%',
                number_format($r['win_rate'], 1).'%',
                (string) $r['trades'],
                $r['pf'] === INF ? 'INF' : number_format($r['pf'], 2),
                number_format($r['dd_pct'], 1).'%',
                number_format($r['holding'], 1).'d',
                $r['country'],
            ])->all(),
        );
    }

    protected function trendTag(?float $r1y, ?float $r3m): string
    {
        if ($r1y === null || $r3m === null) {
            return 'n/a';
        }

        $long = $r1y > 0;
        $short = $r3m > 0;

        if ($long && $short) {
            return 'UP-UP';
        }
        if ($long) {
            return 'up-sideways';
        }
        if ($short) {
            return 'down-bounce';
        }

        return 'DOWN-DOWN';
    }
}
