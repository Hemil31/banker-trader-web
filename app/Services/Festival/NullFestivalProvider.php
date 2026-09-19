<?php

namespace App\Services\Festival;

use App\Contracts\Festival\FestivalProvider;
use DateTimeInterface;

/**
 * Placeholder festival provider: no festival/calendar data source exists yet
 * (see DECISIONS.md), so every date resolves to "no festival" and
 * GeminiPostGenerationService falls back to normal date/category content.
 * Once a real calendar (DB table, external API, etc.) is available, bind
 * FestivalProvider to it in AppServiceProvider instead of this class — no
 * other code needs to change.
 */
class NullFestivalProvider implements FestivalProvider
{
    public function forDate(DateTimeInterface $date): ?array
    {
        return null;
    }
}
