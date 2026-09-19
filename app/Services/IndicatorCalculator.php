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
