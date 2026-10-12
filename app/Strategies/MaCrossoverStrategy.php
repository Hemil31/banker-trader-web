<?php

namespace App\Strategies;

/**
 * Golden-cross trend following: MA5 crosses above MA20.
 */
class MaCrossoverStrategy extends AbstractStrategy
{
    public function key(): string
    {
        return 'ma_crossover';
    }

    public function name(): string
    {
        return 'MA Crossover (5/20)';
    }

    public function stopLossPct(): float
    {
        return 5.0;
    }

    public function targetPct(): float
    {
        return 20.0;
    }

    public function maxHoldDays(): int
    {
        return 40;
    }

    public function trailing(): bool
    {
        return true;
    }

    public function entry(array $ind): bool
    {
        $ma5 = $this->num($ind, 'ma5');
        $ma20 = $this->num($ind, 'ma20');
        $ma5Prev = $this->num($ind, 'ma5_prev');
        $ma20Prev = $this->num($ind, 'ma20_prev');

        if ($ma5 === null || $ma20 === null || $ma5Prev === null || $ma20Prev === null) {
            return false;
        }

        return $ma5 > $ma20 && $ma5Prev <= $ma20Prev;
    }
}
