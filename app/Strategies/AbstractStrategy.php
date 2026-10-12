<?php

namespace App\Strategies;

use App\Strategies\Contracts\Strategy;

/**
 * Sensible defaults for the exit model; strategies override what they need.
 */
abstract class AbstractStrategy implements Strategy
{
    public function stopLossPct(): float
    {
        return 3.0;
    }

    public function targetPct(): float
    {
        return 8.0;
    }

    public function maxHoldDays(): int
    {
        return 15;
    }

    public function trailing(): bool
    {
        return false;
    }

    /**
     * @param  array<string, float|int|null>  $ind
     */
    protected function num(array $ind, string $key): ?float
    {
        $v = $ind[$key] ?? null;

        return is_numeric($v) ? (float) $v : null;
    }
}
