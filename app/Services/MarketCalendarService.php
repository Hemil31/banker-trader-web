<?php

namespace App\Services;

use App\Contracts\MarketCalendar\MarketCalendarProvider;
use App\Models\MarketHoliday;
use Carbon\CarbonInterface;

/**
 * Syncs the bundled Indian market-holiday calendar (see MarketCalendarProvider)
 * into market_holidays, and answers "is this a trading day" for RiskManager.
 */
class MarketCalendarService
{
    public function __construct(
        protected MarketCalendarProvider $provider,
    ) {}

    /**
     * Upsert every known holiday/special-session row for the given MIC
     * (default from config) into market_holidays. Safe to run repeatedly —
     * keyed on [mic, date].
     */
    public function syncHolidays(?string $mic = null): int
    {
        $mic = $mic ?: (string) config('market_calendar.mic');
        $rows = $this->provider->holidays($mic);

        foreach ($rows as $row) {
            MarketHoliday::updateOrCreate(
                ['mic' => $row['mic'], 'date' => $row['date']],
                [
                    'exchange' => $row['exchange'],
                    'day_of_week' => $row['day_of_week'],
                    'is_weekend' => $row['is_weekend'],
                    'is_business_day' => $row['is_business_day'],
                    'holiday_name' => $row['holiday_name'],
                    'is_early_close' => $row['is_early_close'],
                    'open_time' => $row['open_time'],
                    'close_time' => $row['close_time'],
                ],
            );
        }

        return count($rows);
    }

    /**
     * Whether the given date is a normal trading day. DB-first lookup — a
     * date with no synced row is assumed tradable (never invents a holiday
     * from missing data, same "fail open" caution as MarketDataService's
     * staleness checks).
     */
    public function isTradingDay(CarbonInterface $date, ?string $mic = null): bool
    {
        $mic = $mic ?: (string) config('market_calendar.mic');

        $holiday = MarketHoliday::where('mic', $mic)
            ->where('date', $date->toDateString())
            ->first();

        return ! $holiday || $holiday->is_business_day;
    }

    /**
     * The holiday row for today, if any (business-day-blocking or not) —
     * for surfacing a name in halt reasons/UI.
     */
    public function todaysHoliday(?string $mic = null): ?MarketHoliday
    {
        $mic = $mic ?: (string) config('market_calendar.mic');

        return MarketHoliday::where('mic', $mic)
            ->where('date', today()->toDateString())
            ->first();
    }
}
