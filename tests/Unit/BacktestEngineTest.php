<?php

namespace Tests\Unit;

use App\Models\Backtest;
use App\Models\MarketData;
use App\Models\Stock;
use App\Services\BacktestEngine;
use App\Services\SignalScanner;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BacktestEngineTest extends TestCase
{
    use RefreshDatabase;

    private BacktestEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);

        $scanner = $this->createMock(SignalScanner::class);
        $scanner->method('scan')->willReturn($this->tradableSetup());

        $this->engine = app()->makeWith(BacktestEngine::class, ['scanner' => $scanner]);
    }

    public function test_it_books_a_full_exit_at_target3_after_partial_at_target1(): void
    {
        $stock = $this->makeStock('RELIANCE');
        $this->insertBars($stock, [
            ['2025-01-02', 100], // signal day → buys 100 @ 100
            ['2025-01-03', 99],  // hold
            ['2025-01-06', 103.5], // target1 → books half @ 103.5
            ['2025-01-07', 107],  // target3 → closes remaining @ 107
            ['2025-01-08', 108],
        ]);

        $bt = $this->engine->run([$stock], '2025-01-02', '2025-01-08', ['name' => 'T3 test']);

        $this->assertInstanceOf(Backtest::class, $bt);
        $this->assertSame(1, $bt->total_trades, 'T3 exit completes a single trade');
        $this->assertSame(1, $bt->wins);
        $this->assertSame(0, $bt->losses);
        $this->assertGreaterThan((float) $bt->starting_capital, (float) $bt->ending_capital);

        $trades = $bt->meta['trades'];
        $this->assertCount(1, $trades);
        $this->assertSame('RELIANCE', $trades[0]['symbol']);
        $this->assertSame('T3', $trades[0]['reason']);
        $this->assertGreaterThan(0.0, (float) $trades[0]['net']);
        $this->assertGreaterThan(0, $trades[0]['holding_days']);

        $this->assertNotEmpty($bt->equity_curve);
        $this->assertNotEmpty($bt->daily_pnl);
        $this->assertNotEmpty($bt->monthly_pnl);
        $this->assertArrayHasKey('risk.target1_pct', $bt->config_snapshot);
    }

    public function test_it_cuts_losses_at_the_static_stop(): void
    {
        $stock = $this->makeStock('TATAMOTORS');
        $this->insertBars($stock, [
            ['2025-02-03', 100], // buys 100 @ 100, SL at 98
            ['2025-02-04', 97],  // close below SL → exit at stop
        ]);

        $bt = $this->engine->run([$stock], '2025-02-03', '2025-02-04');

        $this->assertSame(1, $bt->total_trades);
        $this->assertSame(0, $bt->wins);
        $this->assertSame(1, $bt->losses);
        $this->assertLessThan((float) $bt->starting_capital, (float) $bt->ending_capital);

        $trades = $bt->meta['trades'];
        $this->assertSame('SL', $trades[0]['reason']);
        $this->assertLessThan(0.0, (float) $trades[0]['net']);

        // Loss is bounded by the stop distance (₹200) + both side costs (~₹70).
        $this->assertGreaterThan(-270.0, (float) $trades[0]['net']);
    }

    public function test_sequential_trades_on_the_same_stock_never_overlap(): void
    {
        $stock = $this->makeStock('HDFCBANK');
        $this->insertBars($stock, [
            ['2025-03-03', 100], // buy #1
            ['2025-03-04', 97],  // SL exit
            ['2025-03-05', 100], // buy #2 permitted (position already closed)
            ['2025-03-06', 103.5], // T1 partial
            ['2025-03-07', 107],  // T3 exit → two full trades total
        ]);

        $bt = $this->engine->run([$stock], '2025-03-03', '2025-03-07');

        $this->assertSame(2, $bt->total_trades, 'Two sequential round-trips on one stock');
        $this->assertSame(1, $bt->wins);
        $this->assertSame(1, $bt->losses);

        $reasons = array_column($bt->meta['trades'], 'reason');
        $this->assertSame(['SL', 'T3'], $reasons);
    }

    private function makeStock(string $symbol): Stock
    {
        return Stock::updateOrCreate(
            ['symbol' => $symbol],
            [
                'name' => $symbol,
                'exchange' => 'NSE',
                'active' => true,
                'in_watchlist' => true,
            ]
        );
    }

    /**
     * @param  array<int, array{string, float}>  $bars
     */
    private function insertBars(Stock $stock, array $bars): void
    {
        foreach ($bars as [$date, $close]) {
            MarketData::create([
                'stock_id' => $stock->id,
                'trade_date' => $date,
                'open' => $close,
                'high' => $close,
                'low' => $close,
                'close' => $close,
                'volume' => 300000,
                'turnover' => 0,
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function tradableSetup(): array
    {
        return [
            'stock' => null,
            'indicators' => [],
            'score' => 72.75,
            'weights' => [],
            'components' => [],
            'decline_score' => 50.0,
            'reversal_score' => 85.0,
            'volume_score' => 55.0,
            'technical_score' => 100.0,
            'liquidity_score' => 55.0,
            'volatility_score' => 100.0,
            'reasons' => ['reversal_confirmations' => 2],
            'tradable' => true,
        ];
    }
}
