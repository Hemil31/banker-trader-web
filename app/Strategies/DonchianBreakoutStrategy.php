<?php

namespace App\Strategies;

/**
 * Trend following: close breaks above the prior 20-day high (Donchian).
 */
class DonchianBreakoutStrategy extends AbstractStrategy
{
    public function key(): string
    {
        return 'donchian_breakout';
    }

    public function name(): string
    {
        return 'Donchian 20-day Breakout';
    }

    public function stopLossPct(): float
    {
        return 5.0;
    }

    public function targetPct(): float
    {
        return 25.0;
    }

    public function maxHoldDays(): int
    {
        return 60;
    }

    public function trailing(): bool
    {
        return true;
    }

    public function entry(array $ind): bool
    {
        $close = $this->num($ind, 'last_close');
        $high = $this->num($ind, 'donchian_high_20');

        if ($close === null || $high === null) {
            return false;
        }

        return $close > $high;
    }
}
