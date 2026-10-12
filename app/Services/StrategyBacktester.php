<?php

namespace App\Services;

use App\Models\Backtest;
use App\Models\MarketData;
use App\Models\Stock;
use App\Models\TradingConfig;
use App\Strategies\BollingerReversionStrategy;
use App\Strategies\Contracts\Strategy;
use App\Strategies\DonchianBreakoutStrategy;
use App\Strategies\DownDayReversionStrategy;
use App\Strategies\MacdCrossoverStrategy;
use App\Strategies\MaCrossoverStrategy;
use App\Strategies\MomentumStrategy;
use App\Strategies\PullbackReversalStrategy;
use App\Strategies\RsiOversoldStrategy;
use App\Strategies\StochasticOversoldStrategy;
use App\Strategies\VolumeBreakoutStrategy;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Walk-forward comparison harness. Replays N entry strategies against the same
 * historical bars, each with its own standard exit model (stop / target /
 * trailing / time-stop) and identical sizing + cost rules, so their edge can be
 * compared apples-to-apples.
 *
 * Indicators are computed once per (stock, trading-day) and shared across every
 * strategy to keep the run fast.
 */
class StrategyBacktester
{
    /** @var array<int, Strategy> */
    protected array $strategies;

    public function __construct(
        protected TradingConfigService $config,
        protected PositionSizer $sizer,
        protected IndicatorCalculator $indicators,
        protected SignalScanner $scanner,
    ) {
        $this->strategies = [
            new PullbackReversalStrategy,
            new MaCrossoverStrategy,
            new RsiOversoldStrategy,
            new MacdCrossoverStrategy,
            new BollingerReversionStrategy,
            new DonchianBreakoutStrategy,
            new VolumeBreakoutStrategy,
            new MomentumStrategy,
            new DownDayReversionStrategy,
            new StochasticOversoldStrategy,
        ];
    }

    /**
     * @return array<int, Strategy>
     */
    public function strategies(): array
    {
        return $this->strategies;
    }

    /**
     * @param  Collection<int, Stock>  $stocks
     * @param  array<string, mixed>  $options
     * @return array<string, Backtest>
     */
    public function run(Collection $stocks, string $start, string $end, array $options = []): array
    {
        $stocks = $stocks->values();

        $snapshot = TradingConfig::allTyped();
        $snapshot = array_merge($snapshot, $options['overrides'] ?? []);
        $snapshot['news.enabled'] = false;
        $this->config->useSnapshot($snapshot);
        $this->sizer->useSnapshot($snapshot);
        $this->scanner->useSnapshot($snapshot);

        $capital = (float) ($options['capital'] ?? $this->config->float('risk.capital', 100000));

        /** @var array<string, Collection<int, MarketData>> $data */
        $data = [];
        foreach ($stocks as $stock) {
            $data[$stock->id] = MarketData::where('stock_id', $stock->id)
                ->orderBy('trade_date')->get()->values();
        }

        $tradeDays = $this->tradeDays($data, $start, $end);
        $indicatorCache = $this->precomputeIndicators($stocks, $data, $tradeDays);

        $results = [];
        foreach ($this->strategies as $strategy) {
            $results[$strategy->key()] = $this->simulate(
                $strategy, $stocks, $data, $tradeDays, $indicatorCache, $start, $end, $capital, $options
            );
        }

        return $results;
    }

    /**
     * @param  Collection<int, Stock>  $stocks
     * @param  array<string, Collection<int, MarketData>>  $data
     * @param  array<int, string>  $tradeDays
     * @return array<string, array<string, array<string, float|int|null>>>
     */
    protected function precomputeIndicators(Collection $stocks, array $data, array $tradeDays): array
    {
        $inRange = array_flip($tradeDays);
        $cache = [];

        foreach ($stocks as $stock) {
            $rows = $data[$stock->id];
            $n = $rows->count();
            for ($i = 0; $i < $n; $i++) {
                $date = $rows[$i]->trade_date->toDateString();
                if (! isset($inRange[$date])) {
                    continue;
                }
                $from = max(0, $i - 254);
                $window = $rows->slice($from, $i - $from + 1)->values();
                $ext = $this->indicators->computeExtended($window);

                // Strategy #1 mirrors the live engine exactly: reuse the real
                // weighted SignalScanner gate rather than a simplified proxy.
                $setup = $this->scanner->scan($stock, $window);
                $ext['live_tradable'] = ($setup !== null && $setup['tradable'] === true) ? 1 : 0;

                $cache[$stock->id][$date] = $ext;
            }
        }

        return $cache;
    }

    /**
     * @param  Collection<int, Stock>  $stocks
     * @param  array<string, Collection<int, MarketData>>  $data
     * @param  array<int, string>  $tradeDays
     * @param  array<string, array<string, array<string, float|int|null>>>  $indicatorCache
     * @param  array<string, mixed>  $options
     */
    protected function simulate(
        Strategy $strategy,
        Collection $stocks,
        array $data,
        array $tradeDays,
        array $indicatorCache,
        string $start,
        string $end,
        float $capital,
        array $options,
    ): Backtest {
        $cash = $capital;
        /** @var array<string, array<string, mixed>> $open */
        $open = [];
        $tradeLog = [];
        $dailyPnl = [];
        $monthlyPnl = [];
        $equity = [];

        $slPct = $strategy->stopLossPct();
        $tgtPct = $strategy->targetPct();
        $maxHold = $strategy->maxHoldDays();
        $trailing = $strategy->trailing();
        $lossCap = $this->config->float('risk.daily_loss_cap', 500);

        foreach ($tradeDays as $idx => $date) {
            $dayRealized = 0.0;

            foreach ($stocks as $stock) {
                $sid = $stock->id;
                $bar = $this->barOn($data[$sid], $date);
                if ($bar === null) {
                    continue;
                }
                $close = (float) $bar->close;
                $high = (float) $bar->high;
                $low = (float) $bar->low;

                if (isset($open[$sid])) {
                    $pos = $open[$sid];
                    $pos['peak'] = max((float) $pos['peak'], $close);
                    $stop = $trailing ? max((float) $pos['sl'], (float) $pos['peak'] * (1 - $slPct / 100)) : (float) $pos['sl'];
                    $target = (float) $pos['entry'] * (1 + $tgtPct / 100);

                    $exitPrice = null;
                    $reason = null;
                    if ($low <= $stop) {
                        $exitPrice = $stop;
                        $reason = 'SL';
                    } elseif ($high >= $target) {
                        $exitPrice = $target;
                        $reason = 'TGT';
                    } elseif (($idx - (int) $pos['entryIdx']) >= $maxHold) {
                        $exitPrice = $close;
                        $reason = 'TIME';
                    }

                    if ($exitPrice !== null) {
                        $qty = (int) $pos['qty'];
                        $exitCost = $this->oneSideCost($exitPrice, $qty);
                        $realized = $qty * ($exitPrice - (float) $pos['entry']) - $exitCost - (float) $pos['entryCost'];
                        $cash += $qty * $exitPrice - $exitCost;
                        $dayRealized += $realized;
                        $tradeLog[] = $this->closedLog(
                            (string) $stock->symbol, $date, $reason, (float) $pos['entry'], $exitPrice,
                            $qty, $realized, (float) $pos['entryCost'] + $exitCost, (string) $pos['date']
                        );
                        unset($open[$sid]);
                    } else {
                        $open[$sid] = $pos;
                    }

                    continue;
                }

                if ($dayRealized <= -$lossCap) {
                    continue;
                }

                $ind = $indicatorCache[$sid][$date] ?? null;
                if ($ind === null || ! $strategy->entry($ind)) {
                    continue;
                }

                $sl = $close * (1 - $slPct / 100);
                $size = $this->sizer->size($close, $sl, $this->exposure($open));
                if ($size['quantity'] <= 0) {
                    continue;
                }

                $cash -= $size['quantity'] * $close;
                $open[$sid] = [
                    'qty' => $size['quantity'],
                    'entry' => $close,
                    'sl' => $sl,
                    'peak' => $close,
                    'entryIdx' => $idx,
                    'entryCost' => $this->oneSideCost($close, $size['quantity']),
                    'date' => $date,
                ];
            }

            $dailyPnl[$date] = ($dailyPnl[$date] ?? 0) + $dayRealized;
            $month = substr($date, 0, 7);
            $monthlyPnl[$month] = ($monthlyPnl[$month] ?? 0) + $dayRealized;
            $equity[$date] = $cash + $this->markOpen($open, $data, $date);
        }

        // Force-close any position still open at the last bar for a fair P&L.
        $lastDate = $tradeDays ? $tradeDays[count($tradeDays) - 1] : $end;
        foreach ($open as $sid => $pos) {
            $bar = $this->barOn($data[$sid], $lastDate);
            $close = $bar !== null ? (float) $bar->close : (float) $pos['entry'];
            $qty = (int) $pos['qty'];
            $exitCost = $this->oneSideCost($close, $qty);
            $realized = $qty * ($close - (float) $pos['entry']) - $exitCost - (float) $pos['entryCost'];
            $symbol = (string) ($stocks->firstWhere('id', $sid)->symbol ?? '?');
            $tradeLog[] = $this->closedLog(
                $symbol, $lastDate, 'EOD', (float) $pos['entry'], $close,
                $qty, $realized, (float) $pos['entryCost'] + $exitCost, (string) $pos['date']
            );
            $cash += $qty * $close - $exitCost;
        }
        $open = [];

        $ending = $cash;
        $stats = $this->computeMetrics($tradeLog, $capital, $ending, $equity, $start, $end);

        return $this->persist(
            $strategy, $start, $end, $capital, $stats, $tradeLog, $dailyPnl, $monthlyPnl, $equity, $options
        );
    }

    /**
     * @param  list<array<string, mixed>>  $trades
     * @param  array<string, float>  $equity
     * @return array<string, float|int|null>
     */
    protected function computeMetrics(array $trades, float $capital, float $ending, array $equity, string $start, string $end): array
    {
        $wins = array_filter($trades, fn ($t) => $t['net'] >= 0);
        $losses = array_filter($trades, fn ($t) => $t['net'] < 0);
        $total = count($trades);
        $winCount = count($wins);
        $lossCount = count($losses);

        $totalCosts = array_sum(array_map(fn ($t) => (float) $t['costs'], $trades));
        $netProfit = $ending - $capital;

        $grossProfit = array_sum(array_map(fn ($t) => max((float) $t['net'], 0), $trades));
        $grossLoss = array_sum(array_map(fn ($t) => max(-(float) $t['net'], 0), $trades));
        $profitFactor = $grossLoss > 0 ? $grossProfit / $grossLoss : ($grossProfit > 0 ? null : 0.0);

        [$maxDd, $maxDdPct] = $this->maxDrawdown($equity);

        $avgProfit = $winCount ? array_sum(array_map(fn ($t) => (float) $t['net'], $wins)) / $winCount : 0.0;
        $avgLoss = $lossCount ? array_sum(array_map(fn ($t) => (float) $t['net'], $losses)) / $lossCount : 0.0;

        $maxLossStreak = 0;
        $cur = 0;
        foreach ($trades as $t) {
            if ((float) $t['net'] < 0) {
                $maxLossStreak = max($maxLossStreak, ++$cur);
            } else {
                $cur = 0;
            }
        }

        $holding = array_map(fn ($t) => (int) $t['holding_days'], $trades);
        $years = max($this->yearsBetween($start, $end), 1 / 365);
        $cagr = $capital > 0
            ? ((max($ending, 0) > 0 ? pow(max($ending, 0) / $capital, 1 / $years) - 1 : -1) * 100)
            : 0.0;

        return [
            'ending_capital' => $ending,
            'total_return_pct' => $capital > 0 ? ($netProfit / $capital) * 100 : 0.0,
            'cagr' => $cagr,
            'total_trades' => $total,
            'wins' => $winCount,
            'losses' => $lossCount,
            'win_rate' => $total ? ($winCount / $total) * 100 : 0.0,
            'avg_profit' => $avgProfit,
            'avg_loss' => $avgLoss,
            'profit_factor' => $profitFactor,
            'max_drawdown' => $maxDd,
            'max_drawdown_pct' => $maxDdPct,
            'max_consecutive_losses' => $maxLossStreak,
            'largest_loss' => $trades ? min(array_map(fn ($t) => (float) $t['net'], $trades)) : 0.0,
            'largest_gain' => $trades ? max(array_map(fn ($t) => (float) $t['net'], $trades)) : 0.0,
            'avg_holding_days' => $holding ? array_sum($holding) / count($holding) : 0.0,
            'total_costs' => $totalCosts,
            'net_profit' => $netProfit,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $tradeLog
     * @param  array<string, float>  $dailyPnl
     * @param  array<string, float>  $monthlyPnl
     * @param  array<string, float>  $equity
     * @param  array<string, float|int|null>  $stats
     * @param  array<string, mixed>  $options
     */
    protected function persist(
        Strategy $strategy,
        string $start,
        string $end,
        float $capital,
        array $stats,
        array $tradeLog,
        array $dailyPnl,
        array $monthlyPnl,
        array $equity,
        array $options,
    ): Backtest {
        $attrs = [
            'name' => 'strategy:'.$strategy->key(),
            'start_date' => $start,
            'end_date' => $end,
            'starting_capital' => $capital,
            'ending_capital' => $stats['ending_capital'],
            'total_return_pct' => $stats['total_return_pct'],
            'cagr' => $stats['cagr'],
            'total_trades' => $stats['total_trades'],
            'wins' => $stats['wins'],
            'losses' => $stats['losses'],
            'win_rate' => $stats['win_rate'],
            'avg_profit' => $stats['avg_profit'],
            'avg_loss' => $stats['avg_loss'],
            'profit_factor' => $stats['profit_factor'],
            'max_drawdown' => $stats['max_drawdown'],
            'max_drawdown_pct' => $stats['max_drawdown_pct'],
            'max_consecutive_losses' => $stats['max_consecutive_losses'],
            'largest_loss' => $stats['largest_loss'],
            'largest_gain' => $stats['largest_gain'],
            'avg_holding_days' => $stats['avg_holding_days'],
            'total_costs' => $stats['total_costs'],
            'net_profit' => $stats['net_profit'],
            'buyhold_return_pct' => 0.0,
            'buyhold_cagr' => 0.0,
            'monthly_pnl' => $monthlyPnl,
            'daily_pnl' => $dailyPnl,
            'equity_curve' => $equity,
            'config_snapshot' => [
                'strategy' => $strategy->key(),
                'stop_loss_pct' => $strategy->stopLossPct(),
                'target_pct' => $strategy->targetPct(),
                'max_hold_days' => $strategy->maxHoldDays(),
                'trailing' => $strategy->trailing(),
            ],
            'meta' => ['trades' => $tradeLog],
        ];

        if ($options['persist'] ?? true) {
            return Backtest::create($attrs);
        }

        return new Backtest($attrs);
    }

    /**
     * @param  array<string, Collection<int, MarketData>>  $data
     * @return array<int, string>
     */
    protected function tradeDays(array $data, string $start, string $end): array
    {
        $lo = Carbon::parse($start)->startOfDay();
        $hi = Carbon::parse($end)->endOfDay();
        $dates = [];

        foreach ($data as $rows) {
            foreach ($rows as $row) {
                if ($row->trade_date >= $lo && $row->trade_date <= $hi) {
                    $dates[$row->trade_date->toDateString()] = true;
                }
            }
        }

        $days = array_keys($dates);
        sort($days);

        return $days;
    }

    /**
     * @param  Collection<int, MarketData>  $rows
     */
    protected function barOn(Collection $rows, string $date): ?MarketData
    {
        $target = Carbon::parse($date)->endOfDay();
        $lo = 0;
        $hi = $rows->count() - 1;
        $idx = -1;

        while ($lo <= $hi) {
            $mid = intdiv($lo + $hi, 2);
            $cell = $rows->get($mid);
            if ($cell !== null && $cell->trade_date <= $target) {
                $idx = $mid;
                $lo = $mid + 1;
            } else {
                $hi = $mid - 1;
            }
        }

        if ($idx < 0) {
            return null;
        }

        $bar = $rows->get($idx);

        return ($bar !== null && $bar->trade_date->toDateString() === $date) ? $bar : null;
    }

    /**
     * @param  array<string, array<string, mixed>>  $open
     * @param  array<string, Collection<int, MarketData>>  $data
     */
    protected function markOpen(array $open, array $data, string $date): float
    {
        $value = 0.0;
        foreach ($open as $sid => $pos) {
            $bar = $this->barOn($data[$sid], $date);
            $price = $bar !== null ? (float) $bar->close : (float) $pos['entry'];
            $value += $price * (int) $pos['qty'];
        }

        return $value;
    }

    /**
     * @param  array<string, array<string, mixed>>  $open
     */
    protected function exposure(array $open): float
    {
        $used = 0.0;
        foreach ($open as $pos) {
            $used += (int) $pos['qty'] * (float) $pos['entry'];
        }

        return $used;
    }

    protected function oneSideCost(float $price, int $quantity): float
    {
        $rate = $this->config->float('costs.one_side_rate_pct', 0.3);
        $slippageBps = $this->config->int('backtest.slippage_bps', 5);
        $fee = ($price * $quantity) * ($rate / 100);
        $slippage = ($price * $quantity) * ($slippageBps / 10000);

        return round($fee + $slippage, 2);
    }

    /**
     * @return array<string, mixed>
     */
    protected function closedLog(
        string $symbol,
        string $date,
        string $reason,
        float $entry,
        float $exit,
        int $qty,
        float $net,
        float $costs,
        string $entryDate,
    ): array {
        return [
            'symbol' => $symbol,
            'exit_date' => $date,
            'reason' => $reason,
            'entry' => round($entry, 2),
            'exit' => round($exit, 2),
            'qty' => $qty,
            'net' => round($net, 2),
            'costs' => round($costs, 2),
            'holding_days' => max(0, (int) floor((strtotime($date) - strtotime($entryDate)) / 86400)),
        ];
    }

    /**
     * @param  array<string, float>  $equity
     * @return array{0: float, 1: float}
     */
    protected function maxDrawdown(array $equity): array
    {
        if ($equity === []) {
            return [0.0, 0.0];
        }

        $peak = -INF;
        $maxDd = 0.0;
        $maxDdPct = 0.0;
        foreach ($equity as $v) {
            $peak = max($peak, $v);
            $dd = $peak - $v;
            if ($dd > $maxDd) {
                $maxDd = $dd;
                $maxDdPct = $peak > 0 ? ($dd / $peak) * 100 : 0.0;
            }
        }

        return [$maxDd, $maxDdPct];
    }

    protected function yearsBetween(string $start, string $end): float
    {
        $startTs = strtotime($start);
        $endTs = strtotime($end);

        return $startTs && $endTs ? abs($endTs - $startTs) / (365 * 24 * 3600) : 1.0;
    }
}
