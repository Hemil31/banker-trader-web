<?php

namespace App\Contracts\MarketCalendar;

/**
 * Reads the bundled trading-calendar data (database/data/xbom_market_holidays.json
 * — generated from apptastic-software/trading-calendar, see NOTICE.md in that
 * folder). Pure PHP, no network/subprocess calls — the production server runs
 * PHP only, so the underlying Python tool is a dev-time-only regeneration
 * step, never a runtime dependency.
 */
class JsonTradingCalendarProvider implements MarketCalendarProvider
{
    public function holidays(string $mic): array
    {
        $path = (string) config('market_calendar.source_file');

        if (! is_file($path)) {
            throw new \RuntimeException("Market calendar data file not found: {$path}");
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        if (! is_array($decoded) || ! isset($decoded['holidays']) || ! is_array($decoded['holidays'])) {
            throw new \RuntimeException("Market calendar data file is malformed: {$path}");
        }

        if (($decoded['mic'] ?? null) !== $mic) {
            throw new \RuntimeException("Market calendar data file is for MIC {$decoded['mic']}, requested {$mic}.");
        }

        return array_map(fn (array $row): array => [
            'mic' => (string) $row['mic'],
            'exchange' => (string) $row['exchange'],
            'date' => (string) $row['date'],
            'day_of_week' => (string) $row['day_of_week'],
            'is_weekend' => (bool) $row['is_weekend'],
            'is_business_day' => (bool) $row['is_business_day'],
            'holiday_name' => isset($row['holiday_name']) ? (string) $row['holiday_name'] : null,
            'is_early_close' => (bool) $row['is_early_close'],
            'open_time' => isset($row['open_time']) ? (string) $row['open_time'] : null,
            'close_time' => isset($row['close_time']) ? (string) $row['close_time'] : null,
        ], $decoded['holidays']);
    }
}
