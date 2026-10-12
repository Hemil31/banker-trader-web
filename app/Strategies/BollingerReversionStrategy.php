<?php

namespace App\Strategies;

/**
 * Mean reversion: close below the lower Bollinger band (20, 2) and turning up.
 */
class BollingerReversionStrategy extends AbstractStrategy
{
    public function key(): string
    {
        return 'bollinger_reversion';
    }

    public function name(): string
    {
        return 'Bollinger Band Reversion';
    }

    public function entry(array $ind): bool
    {
        $close = $this->num($ind, 'last_close');
        $lower = $this->num($ind, 'boll_lower');
        $prev = $this->num($ind, 'prev_close');

        if ($close === null || $lower === null || $prev === null) {
            return false;
        }

        return $close < $lower && $close > $prev;
    }
}
