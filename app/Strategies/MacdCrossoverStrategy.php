<?php

namespace App\Strategies;

/**
 * Trend following: MACD line crosses above its signal line.
 */
class MacdCrossoverStrategy extends AbstractStrategy
{
    public function key(): string
    {
        return 'macd_crossover';
    }

    public function name(): string
    {
        return 'MACD Crossover';
    }

    public function stopLossPct(): float
    {
        return 5.0;
    }

    public function targetPct(): float
    {
        return 15.0;
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
        $macd = $this->num($ind, 'macd');
        $signal = $this->num($ind, 'macd_signal');
        $macdPrev = $this->num($ind, 'macd_prev');
        $signalPrev = $this->num($ind, 'macd_signal_prev');

        if ($macd === null || $signal === null || $macdPrev === null || $signalPrev === null) {
            return false;
        }

        return $macd > $signal && $macdPrev <= $signalPrev;
    }
}
