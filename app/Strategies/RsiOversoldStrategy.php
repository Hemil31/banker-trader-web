<?php

namespace App\Strategies;

/**
 * Mean reversion: RSI oversold and turning up.
 */
class RsiOversoldStrategy extends AbstractStrategy
{
    public function key(): string
    {
        return 'rsi_oversold';
    }

    public function name(): string
    {
        return 'RSI Oversold Reversal';
    }

    public function entry(array $ind): bool
    {
        $rsi = $this->num($ind, 'rsi14');
        $close = $this->num($ind, 'last_close');
        $prev = $this->num($ind, 'prev_close');

        if ($rsi === null || $close === null || $prev === null) {
            return false;
        }

        return $rsi < 32 && $close > $prev;
    }
}
