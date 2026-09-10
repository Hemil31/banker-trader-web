<?php

namespace App\Services;

use App\Models\Backtest;
use App\Models\MarketData;
use App\Models\Stock;
use App\Models\TradingConfig;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Walk-forward backtester that replays the live signal/sizing/exit rules against
 * historical MarketData rows (no order/position rows are created — stats only).
 *
 * The scanner is fed a look-back window ending on each trade day so indicators
 * cannot peek into the future. Each triggered buy holds until:
 *   - SL → full exit at the stop price
 *   - Target3 → full exit at T3
 *   - Target1 → half booked, remaining stop trailed to entry (risk-free)
 *
 * All cost/risk values come from trading_configs (mirrors the ExecutionEngine).
 */
class BacktestEngine
{
    public function __construct(
        protected TradingConfigService $config,
        protected SignalScanner $scanner,
        protected PositionSizer $sizer,
        protected IndicatorCalculator $indicators,
    ) {}

    /**
     * Preloaded per-stock market data (ascending by trade_date).
     *
     * @var array<string, Collection<int, MarketData>>
     */
    protected array $data = [];

    /**
     * Preloaded per-stock closing prices (ascending by trade_date).
     *
     * @var array<string, array<int, float>>
     */
    protected array $closes = [];

    /**
     * Load all market data + closing prices for the universe once so the
     * per-day loops never touch the database.
     *
     * @param  Collection<int, Stock>  $stocks
     */
    protected function preload(Collection $stocks): void
    {
        $this->data = [];
        $this->closes = [];

        foreach ($stocks as $stock) {
            $sid = $stock->id;
            $rows = MarketData::where('stock_id', $sid)->orderBy('trade_date')->get();
            $this->data[$sid] = $rows->values();
            $this->closes[$sid] = $rows->pluck('close')->map(fn ($v) => (float) $v)->all();
        }
    }

    /**
     * Run a full backtest and persist the result to the backtests table.
     *
     * @param  array<int, Stock>|Collection<int, Stock>  $stocks
     * @param  array<string, mixed>  $options
     */
    public function run(array|Collection $stocks, string $start, string $end, array $options = []): Backtest
    {
        $stocks = $stocks instanceof Collection ? $stocks->values() : collect($stocks)->values();

        // Snapshot config + market data so the hot loops never hit the DB.
        $snapshot = TradingConfig::allTyped();
        $snapshot = array_merge($snapshot, $options['overrides'] ?? []);
        $this->config->useSnapshot($snapshot);
        $this->scanner->useSnapshot($snapshot);
        $this->sizer->useSnapshot($snapshot);

        if (isset($options['preload_data']) && isset($options['preload_closes'])) {
            $this->data = $options['preload_data'];
            $this->closes = $options['preload_closes'];
        } else {
            $this->preload($stocks);
        }

        $scanner = $this->scanner;
        $sizer = $this->sizer;

        $capital = (float) ($options['capital'] ?? $this->config->float('risk.capital', 100000));

        $cash = $capital;
        $tradeLog = [];
        $dailyPnl = [];
        $monthlyPnl = [];
        $equity = [];

        /** @var array<string, array{qty: int, entry: float, sl: float, trail: ?float, book: int, booked_net: float, booked_costs: float, entry_cost: float, date: string}> $open */
        $open = [];

        $tradeDays = $this->tradeDays($stocks, $start, $end);
        foreach ($tradeDays as $date) {
            $dayRealized = 0.0;

            foreach ($stocks as $stock) {
                $sid = $stock->id;
                $rows = $this->rowsThrough($sid, $date);
                $lastBar = $rows->last();

                if (! $lastBar || $lastBar->trade_date->toDateString() !== $date) {
                    continue;
                }

                $close = (float) $lastBar->close;

                // Manage an existing open position in this stock first.
                if (isset($open[$sid])) {
                    $kit = $this->manageOpen($open[$sid], $close, $stock->symbol, $date);
                    $cash += $kit['cash_delta'];
                    $dayRealized += $kit['realized'];

                    if ($kit['closed']) {
                        if ($kit['log'] !== null) {
                            $tradeLog[] = $kit['log'];
                        }
                        unset($open[$sid]);
                    } else {
                        $open[$sid] = $kit['position'];
                    }

                    continue;
                }

                // Only enter new positions in the OOS window unless allowAny is set.
                if (! ($options['allow_any'] ?? false) && $date < ($options['oos_start'] ?? $date)) {
                    continue;
                }

                // Daily loss cap → no new entries once the day is under water.
                $lossCap = $this->config->float('risk.daily_loss_cap', 500);
                if ($dayRealized <= -$lossCap) {
                    continue;
                }

                $setup = $scanner->scan($stock, $rows->take(-255));
                if (! $setup || $setup['tradable'] !== true) {
                    continue;
                }

                $sl = $close * (1 - $this->config->float('risk.stop_loss_pct', 2) / 100);
                $used = $this->currentExposure($open);
                $size = $sizer->size($close, $sl, $used);

                if ($size['quantity'] <= 0) {
                    continue;
                }

                $cash -= $size['quantity'] * $close;
                $open[$sid] = [
                    'qty' => $size['quantity'],
                    'entry' => $close,
                    'sl' => $sl,
                    'trail' => null,
                    'book' => 0,
                    'booked_net' => 0.0,
                    'booked_costs' => 0.0,
                    'entry_cost' => $this->oneSideCost($close, $size['quantity']),
                    'date' => $date,
                ];
            }

            $dailyPnl[$date] = ($dailyPnl[$date] ?? 0) + $dayRealized;
            $month = substr($date, 0, 7);
            $monthlyPnl[$month] = ($monthlyPnl[$month] ?? 0) + $dayRealized;

            $equity[$date] = $cash + $this->markAll($open);
        }

        $endingCapital = $cash + $this->markAll($open);
        $netProfit = $endingCapital - $capital;
        $totalCosts = $this->totalCosts($tradeLog, $open);

        $stats = $this->computeMetrics(
            trades: $tradeLog,
            startingCapital: $capital,
            endingCapital: $endingCapital,
            equity: $equity,
            totalCosts: $totalCosts,
            netProfit: $netProfit,
            buyholdReturnPct: $this->buyHoldReturn($stocks, $start, $end),
            start: $start,
            end: $end,
        );

        return $this->persist(
            options: $options,
            start: $start,
            end: $end,
            capital: $capital,
            stats: $stats,
            tradeLog: $tradeLog,
            dailyPnl: $dailyPnl,
            monthlyPnl: $monthlyPnl,
            equity: $equity,
        );
    }

    /**
     * Evaluate one open position against today's close.
     *
     * @param  array<string, mixed>  $position
     * @return array{position: array<string, mixed>, cash_delta: float, realized: float, costs: float, closed: bool, log: ?array<string, mixed>}
     */
    protected function manageOpen(array $position, float $price, string $symbol, string $date): array
    {
        $qty = (int) $position['qty'];
        $booked = (int) $position['book'];
        $bookedNet = (float) $position['booked_net'];
        $bookedCosts = (float) $position['booked_costs'];
        $entryCost = (float) $position['entry_cost'];
        $entry = (float) $position['entry'];
        $stop = (float) ($position['trail'] ?? $position['sl']);
        $remaining = $qty - $booked;

        // SL: full exit at stop.
        if ($price <= $stop) {
            $exitCost = $this->oneSideCost($stop, $remaining);
            $totalCosts = $entryCost + $bookedCosts + $exitCost;
            $realized = $bookedNet + $remaining * ($stop - $entry) - $exitCost - $entryCost;

            $kit = [
                'position' => $position,
                'cash_delta' => $remaining * $stop - $exitCost,
                'realized' => $realized,
                'costs' => $totalCosts,
                'closed' => true,
                'log' => $this->closedLog($symbol, $date, 'SL', $entry, $stop, $remaining, $realized, $totalCosts, $position['date']),
            ];

            return $kit;
        }

        $target3 = $entry * (1 + $this->config->float('risk.target3_pct', 5) / 100);

        // Target3: full exit.
        if ($price >= $target3) {
            $exitCost = $this->oneSideCost($target3, $remaining);
            $totalCosts = $entryCost + $bookedCosts + $exitCost;
            $realized = $bookedNet + $remaining * ($target3 - $entry) - $exitCost - $entryCost;

            $kit = [
                'position' => $position,
                'cash_delta' => $remaining * $target3 - $exitCost,
                'realized' => $realized,
                'costs' => $totalCosts,
                'closed' => true,
                'log' => $this->closedLog($symbol, $date, 'T3', $entry, $target3, $remaining, $realized, $totalCosts, $position['date']),
            ];

            return $kit;
        }

        // Target1: book half, trail stop to entry (risk-free on remaining).
        $target1 = $entry * (1 + $this->config->float('risk.target1_pct', 3) / 100);
        $cashDelta = 0.0;
        $realized = 0.0;
        $costs = 0.0;

        if ($booked === 0 && $price >= $target1) {
            $book = max(1, (int) floor($qty * 0.5));
            $bookCosts = $this->oneSideCost($target1, $book);

            $position['book'] = $book;
            $position['booked_net'] = $bookedNet + $book * ($target1 - $entry) - $bookCosts;
            $position['booked_costs'] = $bookedCosts + $bookCosts;
            $position['trail'] = $entry;

            $cashDelta = $book * $target1 - $bookCosts;
            $realized = $book * ($target1 - $entry) - $bookCosts;
            $costs = $bookCosts;
        }

        return [
            'position' => $position,
            'cash_delta' => $cashDelta,
            'realized' => $realized,
            'costs' => $costs,
            'closed' => false,
            'log' => null,
        ];
    }

    /**
     * @param  array<string, array<string, mixed>>  $openPositions
     */
    protected function currentExposure(array $openPositions): float
    {
        $used = 0.0;
        foreach ($openPositions as $pos) {
            $used += (int) $pos['qty'] * (float) $pos['entry'];
        }

        return $used;
    }

    /**
     * @param  array<string, array<string, mixed>>  $open
     */
    protected function markAll(array $open): float
    {
        $value = 0.0;
        foreach ($open as $sid => $pos) {
            $last = $this->closesFor($sid)->last();
            $value += ((float) ($last ?? $pos['entry'])) * (int) $pos['qty'];
        }

        return $value;
    }

    /**
     * @param  list<array<string, mixed>>  $tradeLog
     * @param  array<string, array<string, mixed>>  $open
     */
    protected function totalCosts(array $tradeLog, array $open): float
    {
        $costs = 0.0;
        foreach ($tradeLog as $t) {
            $costs += (float) ($t['costs'] ?? 0);
        }
        foreach ($open as $pos) {
            $costs += (float) $pos['entry_cost'] + (float) ($pos['booked_costs'] ?? 0);
        }

        return $costs;
    }

    /**
     * @param  array<string, mixed>  $options
     * @param  array<string, float|int|null>  $stats
     * @param  list<array<string, mixed>>  $tradeLog
     * @param  array<string, float>  $dailyPnl
     * @param  array<string, float>  $monthlyPnl
     * @param  array<string, float>  $equity
     */
    protected function persist(
        array $options,
        string $start,
        string $end,
        float $capital,
        array $stats,
        array $tradeLog,
        array $dailyPnl,
        array $monthlyPnl,
        array $equity,
    ): Backtest {
        $attrs = [
            'name' => $options['name'] ?? null,
            'start_date' => $start,
            'end_date' => $end,
            'train_start' => $options['train_start'] ?? null,
            'train_end' => $options['train_end'] ?? null,
            'val_start' => $options['val_start'] ?? null,
            'val_end' => $options['val_end'] ?? null,
            'oos_start' => $options['oos_start'] ?? null,
            'oos_end' => $options['oos_end'] ?? null,
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
            'buyhold_return_pct' => $stats['buyhold_return_pct'],
            'buyhold_cagr' => $stats['buyhold_cagr'],
            'monthly_pnl' => $monthlyPnl,
            'daily_pnl' => $dailyPnl,
            'equity_curve' => $equity,
            'config_snapshot' => $this->configSnapshot(),
            'meta' => ['trades' => $tradeLog],
        ];

        // param sweep: skip DB writes (expensive on slow FS).
        if ($options['persist'] ?? true) {
            return Backtest::create($attrs);
        }

        return new Backtest($attrs);
    }

    /**
     * @return array<string, mixed>
     */
    protected function configSnapshot(): array
    {
        $keys = [
            'risk.stop_loss_pct', 'risk.target1_pct', 'risk.target2_pct', 'risk.target3_pct',
            'risk.amount_per_trade', 'risk.capital', 'risk.daily_loss_cap', 'risk.max_trades_per_day',
            'position.max_pct_per_stock', 'position.max_exposure_pct',
            'product.min_price', 'product.min_score', 'product.min_reversal_confirmations',
            'costs.one_side_rate_pct', 'backtest.slippage_bps',
        ];

        $snapshot = [];
        foreach ($keys as $key) {
            $snapshot[$key] = $this->config->get($key);
        }

        return $snapshot;
    }

    /**
     * @param  Collection<int, Stock>  $stocks
     * @return array<int, string>
     */
    protected function tradeDays(Collection $stocks, string $start, string $end): array
    {
        $dates = [];
        foreach ($stocks as $stock) {
            foreach (MarketData::where('stock_id', $stock->id)
                ->whereBetween('trade_date', [Carbon::parse($start)->startOfDay(), Carbon::parse($end)->endOfDay()])
                ->orderBy('trade_date')
                ->pluck('trade_date') as $d) {
                $dates[$d->toDateString()] = true;
            }
        }

        $days = array_keys($dates);
        sort($days);

        return $days;
    }

    /**
     * Equal-weight buy-and-hold return across all stocks over [start, end].
     *
     * @param  Collection<int, Stock>  $stocks
     */
    protected function buyHoldReturn(Collection $stocks, string $start, string $end): float
    {
        $firstSum = 0.0;
        $lastSum = 0.0;

        foreach ($stocks as $stock) {
            $first = MarketData::where('stock_id', $stock->id)
                ->where('trade_date', '>=', Carbon::parse($start)->startOfDay())
                ->orderBy('trade_date')
                ->value('close');
            $last = MarketData::where('stock_id', $stock->id)
                ->where('trade_date', '<=', Carbon::parse($end)->endOfDay())
                ->orderByDesc('trade_date')
                ->value('close');

            if ($first === null || $last === null) {
                continue;
            }

            $firstSum += (float) $first;
            $lastSum += (float) $last;
        }

        return $firstSum > 0 ? ($lastSum / $firstSum - 1) * 100 : 0.0;
    }

    /**
     * @return Collection<int, MarketData>
     */
    protected function rowsThrough(string $stockId, string $date): Collection
    {
        $rows = $this->data[$stockId] ?? collect();
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

        return $rows->slice(0, $idx + 1)->values();
    }

    /**
     * @return Collection<int, float>
     */
    protected function closesFor(string $stockId): Collection
    {
        return collect($this->closes[$stockId] ?? []);
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
     * @param  list<array<string, mixed>>  $trades
     * @param  array<string, float>  $equity
     * @return array<string, float|int|null>
     */
    protected function computeMetrics(
        array $trades,
        float $startingCapital,
        float $endingCapital,
        array $equity,
        float $totalCosts,
        float $netProfit,
        float $buyholdReturnPct,
        string $start,
        string $end,
    ): array {
        $wins = array_filter($trades, fn ($t) => $t['net'] >= 0);
        $losses = array_filter($trades, fn ($t) => $t['net'] < 0);

        $total = count($trades);
        $winCount = count($wins);
        $lossCount = count($losses);

        $avgProfit = $winCount ? array_sum(array_map(fn ($t) => $t['net'], $wins)) / $winCount : 0.0;
        $avgLoss = $lossCount ? array_sum(array_map(fn ($t) => $t['net'], $losses)) / $lossCount : 0.0;

        $grossProfit = array_sum(array_map(fn ($t) => max($t['net'], 0), $trades));
        $grossLoss = array_sum(array_map(fn ($t) => max(-$t['net'], 0), $trades));
        $profitFactor = $grossLoss > 0 ? $grossProfit / $grossLoss : ($grossProfit > 0 ? null : 0.0);

        [$maxDd, $maxDdPct] = $this->maxDrawdown($equity);

        $largestGain = $trades ? max(array_map(fn ($t) => $t['net'], $trades)) : 0.0;
        $largestLoss = $trades ? min(array_map(fn ($t) => $t['net'], $trades)) : 0.0;

        $maxLossStreak = 0;
        $curStreak = 0;
        foreach ($trades as $t) {
            if ($t['net'] < 0) {
                $curStreak++;
                $maxLossStreak = max($maxLossStreak, $curStreak);
            } else {
                $curStreak = 0;
            }
        }

        $holdingDays = array_map(fn ($t) => $t['holding_days'], $trades);
        $avgHolding = $holdingDays ? array_sum($holdingDays) / count($holdingDays) : 0.0;

        $years = max(self::yearsBetween($start, $end), 1 / 365);
        $cagr = $startingCapital > 0
            ? (max($endingCapital, 0) > 0 ? (pow(max($endingCapital, 0) / $startingCapital, 1 / $years) - 1) * 100 : -100.0)
            : 0.0;

        $buyholdCagr = (pow(max(1 + $buyholdReturnPct / 100, 0), 1 / $years) - 1) * 100;

        return [
            'ending_capital' => $endingCapital,
            'total_return_pct' => $startingCapital > 0 ? ($netProfit / $startingCapital) * 100 : 0.0,
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
            'largest_loss' => $largestLoss,
            'largest_gain' => $largestGain,
            'avg_holding_days' => $avgHolding,
            'total_costs' => $totalCosts,
            'net_profit' => $netProfit,
            'buyhold_return_pct' => $buyholdReturnPct,
            'buyhold_cagr' => $buyholdCagr,
        ];
    }

    /**
     * @param  array<string, float>  $equity
     * @return array{float, float}
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

    protected static function yearsBetween(string $start, string $end): float
    {
        $startTs = strtotime($start);
        $endTs = strtotime($end);

        return $startTs && $endTs
            ? abs($endTs - $startTs) / (365 * 24 * 3600)
            : 1.0;
    }
}
