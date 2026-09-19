<?php

namespace App\Services;

use App\Models\MarketData;
use App\Models\Stock;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Continuous-rotation delivery scalper.
 *
 * Logic:
 *   - Entry: stock shows short-term momentum based ONLY on recent candles —
 *     above MA5, majority of the lookback windows (default last 5/7/12 days)
 *     positive, RSI 40-70, volume average+.  No 1-year/long-term trend is used:
 *     the buy idea comes straight from the last 5/7/12 days of historical data.
 *   - Exit (first trigger wins, full position):
 *     1. +1.5% profit target
 *     2. -1% stop loss
 *     3. close drops below MA5 (trend broken)
 *     4. majority of lookback windows flip negative (recent data turns down)
 *     5. 5 trading-day time stop
 *   - After any exit the stock is freed immediately; next day's scan picks up
 *     the next opportunity (same stock is OK if it requalifies).
 *
 * Self-contained — does NOT touch BacktestEngine or the live ExecutionEngine.
 */
class DeliveryScalper
{
    /**
     * @var array<string, Collection<int, MarketData>>
     */
    protected array $data = [];

    /**
     * @var array<string, array<int, float>>
     */
    protected array $closes = [];

    /**
     * @var list<int>
     */
    protected array $lookbackWindows = [5, 7, 12];

    protected IndicatorCalculator $indicators;

    public function __construct(IndicatorCalculator $indicators)
    {
        $this->indicators = $indicators;
    }

    /**
     * @param  Collection<int, Stock>  $stocks
     * @param  array<string, mixed>  $overrides
     * @return array{stats: array<string, mixed>, trades: list<array<string, mixed>>, dailyPnl: array<string, float>, monthlyPnl: array<string, float>, equityCurve: array<string, float>}
     */
    public function run(
        Collection $stocks,
        string $start,
        string $end,
        float $capital = 100000.0,
        array $overrides = [],
    ): array {
        $tp = $overrides['tp_pct'] ?? 1.5;
        $sl = $overrides['sl_pct'] ?? 1.0;
        $maxHold = $overrides['max_hold_days'] ?? 5;
        $maxPerStock = $overrides['max_pct_per_stock'] ?? 20.0;
        $maxExposure = $overrides['max_exposure_pct'] ?? 70.0;
        $this->lookbackWindows = $overrides['lookback_windows'] ?? [5, 7, 12];

        $this->preload($stocks);
        $tradeDays = $this->tradeDays($stocks, $start, $end);

        $cash = $capital;
        $trades = [];
        $dailyPnl = [];
        $monthlyPnl = [];
        $equity = [];
        $dayRealized = 0.0;

        /** @var array<string, array{qty: int, entry: float, date: string, holdDays: int}> */
        $open = [];

        foreach ($tradeDays as $date) {
            $dayRealized = 0.0;

            // --- 1. Manage existing positions first ---
            foreach (array_keys($open) as $sid) {
                $rows = $this->rowsThrough($sid, $date);
                $lastBar = $rows->last();
                if (! $lastBar || $lastBar->trade_date->toDateString() !== $date) {
                    continue;
                }
                $close = (float) $lastBar->close;
                $pos = $open[$sid];
                $pos['holdDays']++;

                $entry = $pos['entry'];
                $pnlPct = ($close - $entry) / $entry * 100;

                $exitReason = null;
                $exitPrice = $close;

                // TP: +1.5%
                if ($pnlPct >= $tp) {
                    $exitReason = 'TP';
                    $exitPrice = $entry * (1 + $tp / 100);
                }
                // SL: -1%
                elseif ($pnlPct <= -$sl) {
                    $exitReason = 'SL';
                    $exitPrice = $entry * (1 - $sl / 100);
                }
                // Time stop
                elseif ($pos['holdDays'] >= $maxHold) {
                    $exitReason = 'TIME';
                    $exitPrice = $close;
                }
                // Trend break / momentum flip: close < MA5, or recent windows turn down
                else {
                    $ind = $this->indicators->compute($rows);
                    $ma5 = $ind['ma5'] ?? null;
                    $windowsDown = $this->windowsDown($ind);

                    if (($ma5 !== null && $close < (float) $ma5) || $windowsDown) {
                        $exitReason = $windowsDown ? 'MOMENTUM' : 'TREND';
                        $exitPrice = $close;
                    }
                }

                if ($exitReason !== null) {
                    $cost = $this->tradeCost($entry, $exitPrice, $pos['qty']);
                    $netPnl = $pos['qty'] * ($exitPrice - $entry) - $cost;
                    $cash += $pos['qty'] * $exitPrice;
                    $dayRealized += $netPnl;

                    $trades[] = [
                        'symbol' => $stocks->firstWhere('id', $sid)->symbol ?? $sid,
                        'entry_date' => $pos['date'],
                        'exit_date' => $date,
                        'entry' => $entry,
                        'exit' => $exitPrice,
                        'qty' => $pos['qty'],
                        'pnl' => round($netPnl, 2),
                        'pnl_pct' => round($pnlPct, 2),
                        'reason' => $exitReason,
                        'holding_days' => $pos['holdDays'],
                    ];

                    unset($open[$sid]);
                } else {
                    $open[$sid] = $pos;
                }
            }

            // --- 2. Scan for new entries (one per stock max) ---
            if ($dayRealized < 0) {
                // Already losing today → skip new entries (daily guard)
            } else {
                $usedCapital = $this->usedCapital($open);
                foreach ($stocks as $stock) {
                    $sid = $stock->id;
                    if (isset($open[$sid])) {
                        continue; // already in this stock

                    }

                    $stockValue = $usedCapital + array_sum(array_map(
                        fn ($p) => (float) $p['qty'] * (float) $p['entry'],
                        $open,
                    ));

                    if ($stockValue >= $capital * ($maxExposure / 100)) {
                        break; // exposure ceiling
                    }

                    $rows = $this->rowsThrough($sid, $date);
                    $lastBar = $rows->last();
                    if (! $lastBar || $lastBar->trade_date->toDateString() !== $date) {
                        continue;
                    }

                    $close = (float) $lastBar->close;
                    if ($close <= 0 || count($rows) < 50) {
                        continue;
                    }

                    $ind = $this->indicators->compute($rows);
                    if (! $this->entrySignal($ind)) {
                        continue;
                    }

                    $slPrice = $close * (1 - $sl / 100);
                    $riskPerShare = $close - $slPrice;
                    if ($riskPerShare <= 0) {
                        continue;
                    }
                    $riskAmount = $capital * 0.002; // 0.2% of capital risk per trade
                    $qty = max(1, (int) floor($riskAmount / $riskPerShare));
                    $maxQty = (int) floor($capital * ($maxPerStock / 100) / $close);
                    $qty = min($qty, $maxQty);
                    $freeBudget = $capital * ($maxExposure / 100) - $stockValue;
                    $qty = min($qty, (int) floor($freeBudget / $close));

                    if ($qty <= 0 || $close * $qty > $cash) {
                        continue;
                    }

                    $cash -= $close * $qty;
                    $open[$sid] = [
                        'qty' => $qty,
                        'entry' => $close,
                        'date' => $date,
                        'holdDays' => 0,
                    ];
                    // Only enter one stock per day to keep it simple
                    break;
                }
            }

            $dailyPnl[$date] = $dayRealized;
            $month = substr($date, 0, 7);
            $monthlyPnl[$month] = ($monthlyPnl[$month] ?? 0) + $dayRealized;
            $equity[$date] = $cash + $this->markAll($open);
        }

        // Force-close any remaining positions at last available price
        foreach (array_keys($open) as $sid) {
            $pos = $open[$sid];
            $lastClose = $this->closes[$sid] ? (float) end($this->closes[$sid]) : $pos['entry'];
            $cost = $this->tradeCost($pos['entry'], $lastClose, $pos['qty']);
            $pnl = $pos['qty'] * ($lastClose - $pos['entry']) - $cost;
            $cash += $pos['qty'] * $lastClose;

            $trades[] = [
                'symbol' => $stocks->firstWhere('id', $sid)->symbol ?? $sid,
                'entry_date' => $pos['date'],
                'exit_date' => end($tradeDays),
                'entry' => $pos['entry'],
                'exit' => $lastClose,
                'qty' => $pos['qty'],
                'pnl' => round($pnl, 2),
                'pnl_pct' => round(($lastClose - $pos['entry']) / $pos['entry'] * 100, 2),
                'reason' => 'FORCED',
                'holding_days' => $pos['holdDays'],
            ];
        }

        $endingCapital = $cash;
        $netProfit = $endingCapital - $capital;

        $stats = $this->computeMetrics($trades, $capital, $endingCapital, $equity, $netProfit, $start, $end);

        return [
            'stats' => $stats,
            'trades' => $trades,
            'dailyPnl' => $dailyPnl,
            'monthlyPnl' => $monthlyPnl,
            'equityCurve' => $equity,
        ];
    }

    /**
     * Momentum entry based on recent lookback windows only (no long-term trend).
     *
     * The buy idea comes from the last N days (default 5/7/12): the stock must
     * be above its short MA and the majority of the recent windows must be
     * positive — short-term momentum only, nothing annual.
     *
     * @param  array<string, mixed>  $ind
     */
    protected function entrySignal(array $ind): bool
    {
        $close = (float) ($ind['last_close'] ?? 0);
        if ($close <= 0) {
            return false;
        }

        $ma5 = $ind['ma5'] ?? null;
        $rsi = $ind['rsi14'] ?? null;
        $volRatio = $ind['volume_ratio'] ?? null;

        // Short MA anchor only (no MA20/MA50/MA200 — decisions are recent-data based)
        if ($ma5 === null || $close <= (float) $ma5) {
            return false;
        }

        // Majority of the recent windows must be positive (5d, 7d, 12d by default)
        if (! $this->windowsPositive($ind)) {
            return false;
        }

        // RSI 40-70 (not overbought, not oversold)
        if ($rsi === null || (float) $rsi < 40 || (float) $rsi > 70) {
            return false;
        }

        // Volume at least average
        if ($volRatio === null || (float) $volRatio < 1.0) {
            return false;
        }

        return true;
    }

    /**
     * True when the majority of the lookback windows (default 5/7/12 days)
     * show a positive return — the "last 5/7/12 days" buy idea.
     *
     * @param  array<string, mixed>  $ind
     * @param  list<int>|null  $windows
     */
    protected function windowsPositive(array $ind, ?array $windows = null): bool
    {
        $windows ??= $this->lookbackWindows;

        if ($windows === []) {
            return true;
        }

        $positive = 0;
        $available = 0;

        foreach ($windows as $w) {
            $ret = $ind["ret{$w}d"] ?? null;
            if ($ret === null) {
                continue;
            }
            $available++;
            if ((float) $ret > 0) {
                $positive++;
            }
        }

        if ($available === 0) {
            return false;
        }

        return $positive > $available / 2;
    }

    /**
     * True when the majority of the lookback windows are negative — the
     * recent-data sell idea (exit while holding a position).
     *
     * @param  array<string, mixed>  $ind
     * @param  list<int>|null  $windows
     */
    protected function windowsDown(array $ind, ?array $windows = null): bool
    {
        $windows ??= $this->lookbackWindows;

        if ($windows === []) {
            return false;
        }

        $negative = 0;
        $available = 0;

        foreach ($windows as $w) {
            $ret = $ind["ret{$w}d"] ?? null;
            if ($ret === null) {
                continue;
            }
            $available++;
            if ((float) $ret < 0) {
                $negative++;
            }
        }

        if ($available === 0) {
            return false;
        }

        return $negative > $available / 2;
    }

    /**
     * @param  Collection<int, Stock>  $stocks
     */
    protected function preload(Collection $stocks): void
    {
        $this->data = [];
        $this->closes = [];

        foreach ($stocks as $stock) {
            $rows = MarketData::where('stock_id', $stock->id)->orderBy('trade_date')->get();
            $this->data[$stock->id] = $rows->values();
            $this->closes[$stock->id] = $rows->pluck('close')->map(fn ($v) => (float) $v)->all();
        }
    }

    /**
     * @param  Collection<int, Stock>  $stocks
     * @return list<string>
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
     * @return Collection<int, MarketData>
     */
    protected function rowsThrough(string $stockId, string $date): Collection
    {
        $rows = $this->data[$stockId] ?? collect();
        $target = Carbon::parse($date)->endOfDay();

        /** @var int $lo */
        $lo = 0;
        /** @var int $hi */
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
     * @param  array<string, array{qty: int, entry: float, date: string, holdDays: int}>  $open
     */
    protected function usedCapital(array $open): float
    {
        $used = 0.0;
        foreach ($open as $pos) {
            $used += (int) $pos['qty'] * (float) $pos['entry'];
        }

        return $used;
    }

    /**
     * @param  array<string, array{qty: int, entry: float, date: string, holdDays: int}>  $open
     */
    protected function markAll(array $open): float
    {
        $value = 0.0;
        foreach ($open as $sid => $pos) {
            $last = $this->closes[$sid] ? end($this->closes[$sid]) : $pos['entry'];
            $value += (float) $last * (int) $pos['qty'];
        }

        return $value;
    }

    protected function tradeCost(float $entryPrice, float $exitPrice, int $qty): float
    {
        $rate = 0.3; // one-side rate %
        $slippageBps = 5;

        $buyNotional = $entryPrice * $qty;
        $sellNotional = $exitPrice * $qty;

        $fee = ($buyNotional + $sellNotional) * ($rate / 100);
        $slippage = ($buyNotional + $sellNotional) * ($slippageBps / 10000);

        return round($fee + $slippage, 2);
    }

    /**
     * @param  list<array<string, mixed>>  $trades
     * @param  array<string, float>  $equity
     * @return array<string, mixed>
     */
    protected function computeMetrics(
        array $trades,
        float $startingCapital,
        float $endingCapital,
        array $equity,
        float $netProfit,
        string $start,
        string $end,
    ): array {
        $wins = array_filter($trades, fn ($t) => $t['pnl'] >= 0);
        $losses = array_filter($trades, fn ($t) => $t['pnl'] < 0);

        $total = count($trades);
        $winCount = count($wins);
        $lossCount = count($losses);

        $avgProfit = $winCount > 0 ? array_sum(array_map(fn ($t) => $t['pnl'], $wins)) / $winCount : 0.0;
        $avgLoss = $lossCount > 0 ? array_sum(array_map(fn ($t) => $t['pnl'], $losses)) / $lossCount : 0.0;

        $grossProfit = array_sum(array_map(fn ($t) => max($t['pnl'], 0), $trades));
        $grossLoss = array_sum(array_map(fn ($t) => max(-$t['pnl'], 0), $trades));
        $profitFactor = $grossLoss > 0 ? $grossProfit / $grossLoss : ($grossProfit > 0 ? null : 0.0);

        [$maxDd, $maxDdPct] = $this->maxDrawdown($equity);

        $largestGain = $trades ? max(array_map(fn ($t) => $t['pnl'], $trades)) : 0.0;
        $largestLoss = $trades ? min(array_map(fn ($t) => $t['pnl'], $trades)) : 0.0;

        $maxLossStreak = 0;
        $curStreak = 0;
        foreach ($trades as $t) {
            if ($t['pnl'] < 0) {
                $curStreak++;
                $maxLossStreak = max($maxLossStreak, $curStreak);
            } else {
                $curStreak = 0;
            }
        }

        $holdingDays = array_map(fn ($t) => $t['holding_days'], $trades);
        $avgHolding = count($holdingDays) > 0 ? array_sum($holdingDays) / count($holdingDays) : 0.0;

        $years = max(self::yearsBetween($start, $end), 1 / 365);
        $cagr = $startingCapital > 0
            ? (max($endingCapital, 0) > 0 ? (pow(max($endingCapital, 0) / $startingCapital, 1 / $years) - 1) * 100 : -100.0)
            : 0.0;

        // Per-reason breakdown
        $reasonCounts = [];
        foreach ($trades as $t) {
            $r = $t['reason'];
            $reasonCounts[$r] = ($reasonCounts[$r] ?? 0) + 1;
        }

        return [
            'ending_capital' => $endingCapital,
            'total_return_pct' => $startingCapital > 0 ? ($netProfit / $startingCapital) * 100 : 0.0,
            'cagr' => $cagr,
            'total_trades' => $total,
            'wins' => $winCount,
            'losses' => $lossCount,
            'win_rate' => $total > 0 ? ($winCount / $total) * 100 : 0.0,
            'avg_profit' => $avgProfit,
            'avg_loss' => $avgLoss,
            'profit_factor' => $profitFactor,
            'max_drawdown' => $maxDd,
            'max_drawdown_pct' => $maxDdPct,
            'max_consecutive_losses' => $maxLossStreak,
            'largest_gain' => $largestGain,
            'largest_loss' => $largestLoss,
            'avg_holding_days' => $avgHolding,
            'net_profit' => $netProfit,
            'exit_reasons' => $reasonCounts,
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

    protected static function yearsBetween(string $start, string $end): float
    {
        $startTs = strtotime($start);
        $endTs = strtotime($end);

        return $startTs && $endTs
            ? abs($endTs - $startTs) / (365 * 24 * 3600)
            : 1.0;
    }
}
