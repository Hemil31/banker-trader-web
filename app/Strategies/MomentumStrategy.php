<?php

namespace App\Strategies;

/**
 * Relative-strength momentum: strong 20-day return while above MA20.
 */
class MomentumStrategy extends AbstractStrategy
{
    public function key(): string
    {
        return 'momentum_20d';
    }

    public function name(): string
    {
        return 'Momentum 20-day (RS)';
    }

    public function stopLossPct(): float
    {
        return 5.0;
    }

    public function targetPct(): float
    {
        return 18.0;
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
        $ret20 = $this->num($ind, 'ret20d');
        $ma20 = $this->num($ind, 'ma20');
        $close = $this->num($ind, 'last_close');

        if ($ret20 === null || $ma20 === null || $close === null) {
            return false;
        }

        return $ret20 > 5.0 && $close > $ma20;
    }
}
