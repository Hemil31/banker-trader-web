<?php

namespace Tests\Feature\Trading;

use App\Models\MarketData;
use App\Models\Stock;
use App\Models\TradingConfig;
use App\Models\TradingSignal;
use App\Services\SignalEngine;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The engine must never generate a fresh entry signal off stale stored
 * bars (e.g. market:ingest hasn't run in days) — see RiskManager/
 * MarketDataService::isStale() and risk.max_data_staleness_days.
 */
class SignalEngineStalenessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
        TradingConfig::set('news.enabled', false);
    }

    public function test_stale_market_data_is_rejected_and_no_signal_is_generated(): void
    {
        $stock = $this->makeStock();
        $this->seedSeries($stock, staleByDays: 10);

        $result = app(SignalEngine::class)->run(collect([$stock]));

        $this->assertEmpty($result['generated']);
        $this->assertSame('stale_market_data', $result['rejected'][0]['reason']);

        $signal = TradingSignal::where('stock_id', $stock->id)->first();
        $this->assertNotNull($signal);
        $this->assertSame('rejected', $signal->status);
        $this->assertStringStartsWith('stale_market_data', $signal->rejection_reason);
    }

    public function test_fresh_market_data_is_not_rejected_as_stale(): void
    {
        $stock = $this->makeStock();
        $this->seedSeries($stock, staleByDays: 1);

        $result = app(SignalEngine::class)->run(collect([$stock]));

        $reasons = array_column($result['rejected'], 'reason');
        $this->assertNotContains('stale_market_data', $reasons);
    }

    private function makeStock(string $symbol = 'SBIN'): Stock
    {
        return Stock::updateOrCreate(
            ['symbol' => $symbol],
            [
                'name' => 'State Bank of India',
                'exchange' => 'NSE',
                'active' => true,
                'in_watchlist' => true,
            ]
        );
    }

    /**
     * A tradable-shaped pullback+reversal series (mirrors the fixture used by
     * NewsSentimentTest) whose most recent bar is $staleByDays old.
     */
    private function seedSeries(Stock $stock, int $staleByDays): void
    {
        $closes = [100, 99.5, 99, 99, 98.5, 98, 97.5, 97, 96.5, 96, 95.5, 95, 93.5, 92.5, 92, 91.5, 91, 93, 95, 96];
        $count = count($closes);

        foreach ($closes as $i => $close) {
            MarketData::create([
                'stock_id' => $stock->id,
                'trade_date' => today()->subDays($staleByDays + ($count - 1 - $i)),
                'open' => $close,
                'high' => $close + 0.5,
                'low' => $close - 0.5,
                'close' => $close,
                'adjusted_close' => $close,
                'volume' => $i === $count - 1 ? 200000 : 100000,
            ]);
        }
    }
}
