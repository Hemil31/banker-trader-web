<?php

namespace App\Strategies;

/**
 * Breakout confirmed by volume: near a 20-day high with volume >= 2x average.
 */
class VolumeBreakoutStrategy extends AbstractStrategy
{
    public function key(): string
    {
        return 'volume_breakout';
    }

    public function name(): string
    {
        return 'Volume Breakout';
    }

    public function stopLossPct(): float
    {
        return 4.0;
    }

    public function targetPct(): float
    {
        return 15.0;
    }

    public function maxHoldDays(): int
    {
        return 30;
    }

    public function entry(array $ind): bool
    {
        $close = $this->num($ind, 'last_close');
        $high = $this->num($ind, 'donchian_high_20');
        $volRatio = $this->num($ind, 'volume_ratio');

        if ($close === null || $high === null || $volRatio === null) {
            return false;
        }

        return $close >= $high * 0.995 && $volRatio >= 2.0;
    }
}
