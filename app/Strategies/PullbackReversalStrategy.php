<?php

namespace App\Strategies;

/**
 * Pullback + reversal (the current live engine): a stock that has pulled back
 * from its 20-day high and is now showing at least two reversal confirmations
 * (RSI below overbought, positive 5-day momentum, close reclaiming MA5).
 */
class PullbackReversalStrategy extends AbstractStrategy
{
    public function key(): string
    {
        return 'pullback_reversal';
    }

    public function name(): string
    {
        return 'Pullback Reversal (live)';
    }

    public function targetPct(): float
    {
        return 12.0;
    }

    public function maxHoldDays(): int
    {
        return 20;
    }

    public function entry(array $ind): bool
    {
        // When the live scanner snapshot is available (comparison harness),
        // defer to the real weighted gate so this is a faithful reproduction.
        if (array_key_exists('live_tradable', $ind)) {
            return (int) $ind['live_tradable'] === 1;
        }

        $dist = $this->num($ind, 'dist_from_20d_high');
        $close = $this->num($ind, 'last_close');
        $ma5 = $this->num($ind, 'ma5');
        $rsi = $this->num($ind, 'rsi14');
        $ret5 = $this->num($ind, 'ret5d');

        if ($dist === null || $close === null || $ma5 === null || $rsi === null || $ret5 === null) {
            return false;
        }

        if ($dist >= 0) {
            return false; // no pullback to buy
        }

        $confirmations = 0;
        if ($rsi < 55) {
            $confirmations++;
        }
        if ($ret5 > 0) {
            $confirmations++;
        }
        if ($close > $ma5) {
            $confirmations++;
        }

        return $confirmations >= 2;
    }
}
