<?php

namespace App\Strategies;

/**
 * Stochastic oversold cross: %K below 20 and crossing above %D.
 */
class StochasticOversoldStrategy extends AbstractStrategy
{
    public function key(): string
    {
        return 'stochastic_oversold';
    }

    public function name(): string
    {
        return 'Stochastic Oversold Cross';
    }

    public function entry(array $ind): bool
    {
        $k = $this->num($ind, 'stoch_k');
        $d = $this->num($ind, 'stoch_d');
        $prevK = $this->num($ind, 'stoch_prev_k');
        $prevD = $this->num($ind, 'stoch_prev_d');

        if ($k === null || $d === null || $prevK === null || $prevD === null) {
            return false;
        }

        return $k < 20 && $k > $d && $prevK <= $prevD;
    }
}
