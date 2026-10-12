<?php

namespace App\Services;

use App\Models\MarketData;
use Illuminate\Support\Collection;

/**
 * Pure indicator math over a series of MarketData rows (ordered by date).
 * Returns associative values for the last bar; used by the scanner and backtester.
 */
class IndicatorCalculator
{
    public const ATR_PERIOD = 14;

    public const RSI_PERIOD = 14;

    /**
     * Compute indicators anchored at the last row of a chronological series.
     *
     * @param  Collection<int, MarketData>  $rows
     * @return array<string, float|int|null>
     */
    public function compute(Collection $rows): array
    {
        $closes = $rows->pluck('close')->map(fn ($v) => (float) $v);
        $n = $closes->count();

        if ($n < 2) {
            return $this->empty();
        }

        return [
            'rsi14' => $this->rsi($closes, self::RSI_PERIOD),
            'atr14' => $this->atr($rows, self::ATR_PERIOD),
            'ma5' => $this->sma($closes, 5),
            'ma10' => $this->sma($closes, 10),
            'ma20' => $this->sma($closes, 20),
            'ma50' => $this->sma($closes, min(50, $n)),
            'ma200' => $n >= 200 ? $this->sma($closes, 200) : null,
            'ret3d' => $n >= 4 ? $this->pctChange($closes, 3) : null,
            'ret5d' => $n >= 6 ? $this->pctChange($closes, 5) : null,
            'ret7d' => $n >= 8 ? $this->pctChange($closes, 7) : null,
            'ret10d' => $n >= 11 ? $this->pctChange($closes, 10) : null,
            'ret12d' => $n >= 13 ? $this->pctChange($closes, 12) : null,
            'ret15d' => $n >= 16 ? $this->pctChange($closes, 15) : null,
            'dist_from_20d_high' => $n >= 20 ? $this->distanceFromHigh($closes, 20) : null,
            'volume_ratio' => $this->volumeRatio($rows, 20),
            'avg_volume_20d' => $this->avgVolume($rows, 20),
            'last_close' => (float) $closes->last(),
            'prev_close' => (float) $closes[$n - 2],
            'last_volume' => (float) ($rows->last()->volume ?? 0),
            'daily_range_pct' => $this->dailyRangePct($rows->last()),
        ];
    }

    /**
     * Wilder's RSI.
     *
     * @param  Collection<int, float>|array<int, float>  $closes
     */
    public function rsi(Collection|array $closes, int $period = 14): ?float
    {
        $closes = $this->toFloats($closes);
        $n = count($closes);
        if ($n < $period + 1) {
            return null;
        }

        $gains = [];
        $losses = [];
        for ($i = 1; $i < count($closes); $i++) {
            $diff = $closes[$i] - $closes[$i - 1];
            $gains[] = max($diff, 0);
            $losses[] = max(-$diff, 0);
        }

        $avgGain = array_sum(array_slice($gains, 0, $period)) / $period;
        $avgLoss = array_sum(array_slice($losses, 0, $period)) / $period;

        for ($i = $period; $i < count($gains); $i++) {
            $avgGain = (($avgGain * ($period - 1)) + $gains[$i]) / $period;
            $avgLoss = (($avgLoss * ($period - 1)) + $losses[$i]) / $period;
        }

        if ($avgLoss == 0) {
            return 100.0;
        }

        $rs = $avgGain / $avgLoss;

        return 100 - (100 / (1 + $rs));
    }

    /**
     * Wilder's ATR (true range averaged).
     *
     * @param  Collection<int, MarketData>  $rows
     */
    public function atr(Collection $rows, int $period = 14): ?float
    {
        $rows = $rows->values();
        $n = $rows->count();
        if ($n < $period + 1) {
            return null;
        }

        $trs = [];
        for ($i = 1; $i < $n; $i++) {
            $trs[] = $this->trueRange($rows[$i], $rows[$i - 1]);
        }

        $atr = array_sum(array_slice($trs, 0, $period)) / $period;
        for ($i = $period; $i < count($trs); $i++) {
            $atr = (($atr * ($period - 1)) + $trs[$i]) / $period;
        }

        return $atr;
    }

    /**
     * @param  Collection<int, float>|array<int, float>  $values
     */
    public function sma(Collection|array $values, int $period): ?float
    {
        $values = $this->toFloats($values);
        $n = count($values);
        if ($period <= 0 || $n < $period) {
            return null;
        }

        return array_sum(array_slice($values, $n - $period)) / $period;
    }

    /**
     * @param  Collection<int, float>|array<int, float>  $closes
     */
    public function pctChange(Collection|array $closes, int $lookback): ?float
    {
        $closes = $this->toFloats($closes);
        $n = count($closes);
        if ($n <= $lookback) {
            return null;
        }

        $start = $closes[$n - 1 - $lookback];

        return ($start > 0) ? (($closes[$n - 1] - $start) / $start) * 100 : null;
    }

    /**
     * Distance (percent) of last close below the highest high over the window.
     *
     * @param  Collection<int, float>|array<int, float>  $closes
     */
    public function distanceFromHigh(Collection|array $closes, int $window): ?float
    {
        $closes = $this->toFloats($closes);
        $n = count($closes);
        if ($window <= 0 || $n < $window) {
            return null;
        }

        $sub = array_slice($closes, $n - $window);
        if ($sub === []) {
            return null;
        }

        $high = max($sub);

        return ($high > 0) ? (($closes[$n - 1] - $high) / $high) * 100 : null;
    }

    /**
     * @param  Collection<int, MarketData>  $rows
     */
    public function volumeRatio(Collection $rows, int $period): ?float
    {
        $avg = $this->avgVolume($rows, $period);
        if (! $avg || $avg <= 0) {
            return null;
        }

        return (float) ($rows->last()->volume ?? 0) / $avg;
    }

    /**
     * @param  Collection<int, MarketData>  $rows
     */
    public function avgVolume(Collection $rows, int $period): ?float
    {
        $rows = $rows->values();
        $n = $rows->count();
        if ($period <= 0 || $n < $period) {
            return null;
        }

        $slice = $rows->slice($n - $period);
        $sum = $slice->sum(fn ($r) => (float) $r->volume);

        return $sum / $period;
    }

    public function dailyRangePct(?MarketData $row): ?float
    {
        if (! $row || (float) $row->close <= 0) {
            return null;
        }

        return ((float) $row->high - (float) $row->low) / (float) $row->close * 100;
    }

    protected function trueRange(MarketData $current, MarketData $prev): float
    {
        $h = (float) $current->high;
        $l = (float) $current->low;
        $pc = (float) $prev->close;

        return max($h - $l, abs($h - $pc), abs($l - $pc));
    }

    /**
     * @param  array<int, float|string>|Collection<int, float>  $values
     * @return array<int, float>
     */
    protected function toFloats(Collection|array $values): array
    {
        return array_map('floatval', $values instanceof Collection ? $values->all() : $values);
    }

    /**
     * Extended indicator set used by the strategy comparison harness: the base
     * indicators plus MACD, Bollinger, Donchian, Stochastic and cross-over
     * extras. Additive — the live scanner keeps using compute().
     *
     * @param  Collection<int, MarketData>  $rows
     * @return array<string, float|int|null>
     */
    public function computeExtended(Collection $rows): array
    {
        $ind = $this->compute($rows);

        $closes = $rows->pluck('close')->map(fn ($v) => (float) $v)->all();
        $highs = $rows->pluck('high')->map(fn ($v) => (float) $v)->all();
        $lows = $rows->pluck('low')->map(fn ($v) => (float) $v)->all();
        $n = count($closes);

        $ind['prev_close'] = $n >= 2 ? $closes[$n - 2] : null;
        $ind['ret20d'] = $n >= 21 ? $this->pctChange($closes, 20) : null;

        $prevCloses = array_slice($closes, 0, max(0, $n - 1));
        $ind['ma5_prev'] = $this->sma($prevCloses, 5);
        $ind['ma20_prev'] = $this->sma($prevCloses, 20);

        // MACD (12, 26, 9).
        $ema12 = $this->emaSeries($closes, 12);
        $ema26 = $this->emaSeries($closes, 26);
        $macdSeries = [];
        foreach ($closes as $i => $_) {
            $macdSeries[$i] = $ema12[$i] - $ema26[$i];
        }
        $signalSeries = $this->emaSeries($macdSeries, 9);
        $m = count($macdSeries);
        $ind['macd'] = $m ? $macdSeries[$m - 1] : null;
        $ind['macd_signal'] = $m ? $signalSeries[$m - 1] : null;
        $ind['macd_prev'] = $m >= 2 ? $macdSeries[$m - 2] : null;
        $ind['macd_signal_prev'] = $m >= 2 ? $signalSeries[$m - 2] : null;

        // Bollinger Bands (20, 2).
        $ind['boll_mid'] = $this->sma($closes, 20);
        $sd = $this->stddev($closes, 20);
        $ind['boll_upper'] = ($ind['boll_mid'] !== null && $sd !== null) ? $ind['boll_mid'] + 2 * $sd : null;
        $ind['boll_lower'] = ($ind['boll_mid'] !== null && $sd !== null) ? $ind['boll_mid'] - 2 * $sd : null;

        // Donchian channel over the prior window, excluding today's bar.
        $high20 = $n >= 21 ? array_slice($highs, $n - 21, 20) : [];
        $low10 = $n >= 11 ? array_slice($lows, $n - 11, 10) : [];
        $ind['donchian_high_20'] = $high20 !== [] ? max($high20) : null;
        $ind['donchian_low_10'] = $low10 !== [] ? min($low10) : null;

        // Stochastic (14, 3).
        [$k, $d, $prevK, $prevD] = $this->stochastic($highs, $lows, $closes, 14, 3);
        $ind['stoch_k'] = $k;
        $ind['stoch_d'] = $d;
        $ind['stoch_prev_k'] = $prevK;
        $ind['stoch_prev_d'] = $prevD;

        // Consecutive down closes ending today.
        $down = 0;
        for ($i = $n - 1; $i >= 1; $i--) {
            if ($closes[$i] < $closes[$i - 1]) {
                $down++;
            } else {
                break;
            }
        }
        $ind['consecutive_down'] = $down;

        return $ind;
    }

    /**
     * Exponential moving average series (seeded with the first value).
     *
     * @param  array<int, float>  $values
     * @return array<int, float>
     */
    protected function emaSeries(array $values, int $period): array
    {
        $k = 2 / ($period + 1);
        $out = [];
        $prev = null;

        foreach ($values as $v) {
            $prev = $prev === null ? $v : ($v * $k) + ($prev * (1 - $k));
            $out[] = $prev;
        }

        return $out;
    }

    /**
     * Population standard deviation of the last $period values.
     *
     * @param  array<int, float>  $values
     */
    protected function stddev(array $values, int $period): ?float
    {
        $n = count($values);
        if ($n < $period || $period <= 0) {
            return null;
        }

        $slice = array_slice($values, $n - $period);
        $mean = array_sum($slice) / $period;

        $var = 0.0;
        foreach ($slice as $v) {
            $var += ($v - $mean) ** 2;
        }

        return sqrt($var / $period);
    }

    /**
     * Stochastic %K/%D (current + previous).
     *
     * @param  array<int, float>  $highs
     * @param  array<int, float>  $lows
     * @param  array<int, float>  $closes
     * @return array{0: ?float, 1: ?float, 2: ?float, 3: ?float}
     */
    protected function stochastic(array $highs, array $lows, array $closes, int $kPeriod, int $dPeriod): array
    {
        $n = count($closes);
        if ($n < $kPeriod + $dPeriod) {
            return [null, null, null, null];
        }

        $kSeries = [];
        for ($i = $kPeriod - 1; $i < $n; $i++) {
            $hSlice = array_slice($highs, $i - $kPeriod + 1, $kPeriod);
            $lSlice = array_slice($lows, $i - $kPeriod + 1, $kPeriod);
            if ($hSlice === [] || $lSlice === []) {
                $kSeries[] = 50.0;

                continue;
            }
            $h = max($hSlice);
            $l = min($lSlice);
            $range = $h - $l;
            $kSeries[] = $range > 0 ? (($closes[$i] - $l) / $range) * 100 : 50.0;
        }

        $m = count($kSeries);
        $dSeries = [];
        for ($i = $dPeriod - 1; $i < $m; $i++) {
            $dSeries[] = array_sum(array_slice($kSeries, $i - $dPeriod + 1, $dPeriod)) / $dPeriod;
        }

        return [
            $kSeries[$m - 1] ?? null,
            $dSeries ? $dSeries[count($dSeries) - 1] : null,
            $m >= 2 ? $kSeries[$m - 2] : null,
            count($dSeries) >= 2 ? $dSeries[count($dSeries) - 2] : null,
        ];
    }

    /**
     * @return array<string, float|int|null>
     */
    protected function empty(): array
    {
        return [
            'rsi14' => null, 'atr14' => null, 'ma5' => null, 'ma10' => null,
            'ma20' => null, 'ma50' => null, 'ma200' => null, 'ret3d' => null,
            'ret5d' => null, 'ret7d' => null, 'ret10d' => null,
            'ret12d' => null, 'ret15d' => null,
            'dist_from_20d_high' => null, 'volume_ratio' => null,
            'avg_volume_20d' => null, 'last_close' => null, 'last_volume' => null,
            'daily_range_pct' => null,
        ];
    }
}
