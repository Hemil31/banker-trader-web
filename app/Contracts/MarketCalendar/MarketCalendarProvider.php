<?php

namespace App\Contracts\MarketCalendar;

/**
 * Pluggable market-holiday calendar lookup. MarketCalendarService consumes
 * only this interface, so the data source (today a bundled static file, see
 * JsonTradingCalendarProvider) can be swapped later — e.g. for a live API —
 * without touching the sync/risk-engine code.
 */
interface MarketCalendarProvider
{
    /**
     * All holiday / special-session rows known for the given MIC.
     *
     * @return array<int, array{
     *     mic: string,
     *     exchange: string,
     *     date: string,
     *     day_of_week: string,
     *     is_weekend: bool,
     *     is_business_day: bool,
     *     holiday_name: ?string,
     *     is_early_close: bool,
     *     open_time: ?string,
     *     close_time: ?string,
     * }>
     */
    public function holidays(string $mic): array;
}
