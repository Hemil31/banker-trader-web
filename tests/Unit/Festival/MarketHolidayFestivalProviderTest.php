<?php

namespace Tests\Unit\Festival;

use App\Models\MarketHoliday;
use App\Services\Festival\MarketHolidayFestivalProvider;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MarketHolidayFestivalProviderTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_festival_for_a_named_holiday(): void
    {
        MarketHoliday::create([
            'mic' => 'XBOM',
            'exchange' => 'Bombay Stock Exchange',
            'date' => '2026-01-26',
            'day_of_week' => 'Monday',
            'is_weekend' => false,
            'is_business_day' => false,
            'holiday_name' => 'Republic Day',
            'is_early_close' => false,
        ]);

        $festival = (new MarketHolidayFestivalProvider)->forDate(CarbonImmutable::parse('2026-01-26'));

        $this->assertSame('Republic Day', $festival['name']);
        $this->assertSame('Indian stock market holiday', $festival['type']);
    }

    public function test_returns_null_for_an_unnamed_holiday_row(): void
    {
        MarketHoliday::create([
            'mic' => 'XBOM',
            'exchange' => 'Bombay Stock Exchange',
            'date' => '2024-01-22',
            'day_of_week' => 'Monday',
            'is_weekend' => false,
            'is_business_day' => false,
            'holiday_name' => null,
            'is_early_close' => false,
        ]);

        $festival = (new MarketHolidayFestivalProvider)->forDate(CarbonImmutable::parse('2024-01-22'));

        $this->assertNull($festival);
    }

    public function test_returns_null_when_no_holiday_row_exists(): void
    {
        $festival = (new MarketHolidayFestivalProvider)->forDate(CarbonImmutable::parse('2026-06-15'));

        $this->assertNull($festival);
    }
}
