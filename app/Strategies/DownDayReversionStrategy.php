<?php

namespace App\Strategies;

/**
 * Short-term mean reversion after a multi-day slide: 3+ down days with RSI weak.
 */
class DownDayReversionStrategy extends AbstractStrategy
{
    public function key(): string
    {
        return 'downday_reversion';
    }

    public function name(): string
    {
        return 'Down-Day Reversion (3d)';
    }

    public function targetPct(): float
    {
        return 6.0;
    }

    public function maxHoldDays(): int
    {
        return 10;
    }

    public function entry(array $ind): bool
    {
        $down = $this->num($ind, 'consecutive_down');
        $rsi = $this->num($ind, 'rsi14');

        if ($down === null || $rsi === null) {
            return false;
        }

        return $down >= 3 && $rsi < 35;
    }
}
