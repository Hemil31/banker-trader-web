<?php

namespace App\Console\Commands;

use App\Services\MarketCalendarService;
use Illuminate\Console\Command;

/**
 * Syncs the bundled Indian market-holiday calendar into market_holidays so
 * RiskManager can block new entries on non-trading days.
 */
class MarketCalendarSync extends Command
{
    protected $signature = 'market-calendar:sync
        {--mic= : MIC code to sync (defaults to config market_calendar.mic)}';

    protected $description = 'Sync the bundled market-holiday calendar into the database';

    public function handle(MarketCalendarService $calendar): int
    {
        $mic = $this->option('mic') ?: null;

        try {
            $count = $calendar->syncHolidays($mic);
        } catch (\Throwable $e) {
            $this->error("Market calendar sync failed: {$e->getMessage()}");

            return self::FAILURE;
        }

        $this->info("Synced {$count} market holiday rows.");

        return self::SUCCESS;
    }
}
