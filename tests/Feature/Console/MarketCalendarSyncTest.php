<?php

namespace Tests\Feature\Console;

use App\Models\MarketHoliday;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Runs against the real bundled database/data/xbom_market_holidays.json (no
 * fake provider) — an end-to-end check that the shipped data file is valid
 * and the whole sync pipeline works, not just the unit-level pieces.
 */
class MarketCalendarSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_sync_command_loads_the_bundled_xbom_data(): void
    {
        $this->artisan('market-calendar:sync')->assertSuccessful();

        $this->assertGreaterThan(0, MarketHoliday::where('mic', 'XBOM')->count());
        $this->assertDatabaseHas('market_holidays', [
            'mic' => 'XBOM',
            'date' => '2026-01-26',
            'holiday_name' => 'Republic Day',
            'is_business_day' => false,
        ]);
    }

    public function test_sync_command_accepts_a_mic_override(): void
    {
        $this->artisan('market-calendar:sync', ['--mic' => 'XBOM'])->assertSuccessful();

        $this->assertGreaterThan(0, MarketHoliday::count());
    }
}
