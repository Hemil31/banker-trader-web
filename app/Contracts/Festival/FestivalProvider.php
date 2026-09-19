<?php

namespace App\Contracts\Festival;

use DateTimeInterface;

/**
 * Pluggable festival/holiday calendar lookup. GeminiPostGenerationService
 * consumes only this interface, so a real calendar data source can be wired
 * in later (bound in AppServiceProvider) without touching the generation
 * pipeline. The default binding, NullFestivalProvider, returns no festival
 * for any date — the app never invents one on its own.
 */
interface FestivalProvider
{
    /**
     * Look up the festival/event to associate with a given date, if any. When
     * more than one event falls on the same date, choosing which one is
     * "the" event for that date is this implementation's job (e.g. a
     * priority/importance column on the calendar data), not the caller's.
     *
     * @return array{name: string, type: string}|null
     */
    public function forDate(DateTimeInterface $date): ?array;
}
