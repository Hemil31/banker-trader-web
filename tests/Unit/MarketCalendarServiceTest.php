<?php

namespace Tests\Unit;

use App\Contracts\MarketCalendar\MarketCalendarProvider;
use App\Models\MarketHoliday;
use App\Services\MarketCalendarService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MarketCalendarServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function service(FakeMarketCalendarProvider $fake): MarketCalendarService
    {
        $this->app->instance(MarketCalendarProvider::class, $fake);

        return $this->app->make(MarketCalendarService::class);
    }

    public function test_sync_holidays_upserts_rows(): void
    {
        $fake = new FakeMarketCalendarProvider([
            $this->row('2026-01-26', 'Republic Day'),
            $this->row('2026-03-04', 'Holi'),
        ]);

        $count = $this->service($fake)->syncHolidays('XBOM');

        $this->assertSame(2, $count);
        $this->assertDatabaseHas('market_holidays', [
            'mic' => 'XBOM',
            'date' => '2026-01-26',
            'holiday_name' => 'Republic Day',
            'is_business_day' => false,
        ]);
        $this->assertDatabaseHas('market_holidays', [
            'mic' => 'XBOM',
            'date' => '2026-03-04',
            'holiday_name' => 'Holi',
        ]);
    }

    public function test_sync_holidays_is_idempotent(): void
    {
        $fake = new FakeMarketCalendarProvider([$this->row('2026-01-26', 'Republic Day')]);
        $service = $this->service($fake);

        $service->syncHolidays('XBOM');
        $service->syncHolidays('XBOM');

        $this->assertSame(1, MarketHoliday::where('mic', 'XBOM')->where('date', '2026-01-26')->count());
    }

    public function test_is_trading_day_is_false_on_a_synced_holiday(): void
    {
        $fake = new FakeMarketCalendarProvider([$this->row('2026-01-26', 'Republic Day')]);
        $service = $this->service($fake);
        $service->syncHolidays('XBOM');

        $this->assertFalse($service->isTradingDay(CarbonImmutable::parse('2026-01-26'), 'XBOM'));
    }

    public function test_is_trading_day_defaults_true_when_no_data_synced(): void
    {
        $service = $this->service(new FakeMarketCalendarProvider([]));

        $this->assertTrue($service->isTradingDay(today(), 'XBOM'));
    }

    /**
     * @return array{mic: string, exchange: string, date: string, day_of_week: string, is_weekend: bool, is_business_day: bool, holiday_name: ?string, is_early_close: bool, open_time: ?string, close_time: ?string}
     */
    protected function row(string $date, ?string $name): array
    {
        return [
            'mic' => 'XBOM',
            'exchange' => 'Bombay Stock Exchange',
            'date' => $date,
            'day_of_week' => 'Monday',
            'is_weekend' => false,
            'is_business_day' => false,
            'holiday_name' => $name,
            'is_early_close' => false,
            'open_time' => null,
            'close_time' => null,
        ];
    }
}

class FakeMarketCalendarProvider implements MarketCalendarProvider
{
    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    public function __construct(protected array $rows) {}

    public function holidays(string $mic): array
    {
        return $this->rows;
    }
}
