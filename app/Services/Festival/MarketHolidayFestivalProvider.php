<?php

namespace App\Services\Festival;

use App\Contracts\Festival\FestivalProvider;
use App\Models\MarketHoliday;
use DateTimeInterface;

/**
 * Real FestivalProvider backed by the synced Indian market-holiday calendar
 * (market_holidays, see MarketCalendarService) — replaces NullFestivalProvider
 * now that a calendar data source exists. Only named holidays are surfaced
 * (a few bundled rows have no name — see database/data/NOTICE.md — those stay
 * "no festival" rather than showing a blank name in a generated post).
 */
class MarketHolidayFestivalProvider implements FestivalProvider
{
    public function forDate(DateTimeInterface $date): ?array
    {
        $holiday = MarketHoliday::where('mic', (string) config('market_calendar.mic'))
            ->where('date', $date->format('Y-m-d'))
            ->whereNotNull('holiday_name')
            ->first();

        if (! $holiday) {
            return null;
        }

        return [
            'name' => $holiday->holiday_name,
            'type' => 'Indian stock market holiday',
        ];
    }
}
