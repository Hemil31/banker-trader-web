<?php

namespace App\Strategies\Contracts;

/**
 * A pluggable entry rule evaluated over an extended indicator snapshot.
 * Exit behaviour (stop / target / trailing / time-stop) travels with the
 * strategy so each is backtested with a sensible, standard risk model.
 */
interface Strategy
{
    /** Stable machine key (used as the backtest name suffix). */
    public function key(): string;

    /** Human-readable strategy name. */
    public function name(): string;

    /** Stop-loss distance from entry, in percent. */
    public function stopLossPct(): float;

    /** Profit target from entry, in percent. */
    public function targetPct(): float;

    /** Time-stop: force-exit after this many trading days. */
    public function maxHoldDays(): int;

    /** When true the stop ratchets up with the highest close since entry. */
    public function trailing(): bool;

    /**
     * Decide whether to enter on the last bar of the series.
     *
     * @param  array<string, float|int|null>  $ind  extended indicator snapshot
     */
    public function entry(array $ind): bool;
}
