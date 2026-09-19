<?php

namespace Tests\Feature\Trading;

use App\Models\ErrorLog;
use App\Models\Position;
use App\Models\Stock;
use App\Models\SystemEvent;
use App\Models\TradingAccount;
use App\Models\TradingConfig;
use App\Models\User;
use App\Services\PositionReconciliationService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PositionReconciliationServiceTest extends TestCase
{
    use RefreshDatabase;

    private TradingAccount $account;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);

        $user = User::factory()->create();
        $this->account = TradingAccount::factory()->for($user)->connectedMegaBull()->create();

        config(['brokers.megabull.api_base' => 'https://api.megabull.in']);
    }

    public function test_matching_positions_report_ok_and_do_not_halt_trading(): void
    {
        $stock = Stock::updateOrCreate(['symbol' => 'RELIANCE'], ['name' => 'Reliance', 'exchange' => 'NSE', 'active' => true, 'in_watchlist' => true]);
        Position::factory()->create([
            'trading_account_id' => $this->account->id,
            'stock_id' => $stock->id,
            'status' => 'open',
            'quantity' => 10,
        ]);

        Http::fake([
            'https://api.megabull.in/api/position/my' => Http::response([], 200),
            'https://api.megabull.in/api/holding/my' => Http::response([
                ['instrumentName' => 'RELIANCE', 'qty' => 10, 'priceAvg' => 2500, 'pl' => 0],
            ], 200),
        ]);

        $result = app(PositionReconciliationService::class)->reconcileAccount($this->account);

        $this->assertSame('ok', $result['status']);
        $this->assertFalse(TradingConfig::get('system.trading_halted'));
    }

    public function test_quantity_mismatch_halts_trading_and_logs_it(): void
    {
        $stock = Stock::updateOrCreate(['symbol' => 'RELIANCE'], ['name' => 'Reliance', 'exchange' => 'NSE', 'active' => true, 'in_watchlist' => true]);
        Position::factory()->create([
            'trading_account_id' => $this->account->id,
            'stock_id' => $stock->id,
            'status' => 'open',
            'quantity' => 10,
        ]);

        Http::fake([
            'https://api.megabull.in/api/position/my' => Http::response([], 200),
            'https://api.megabull.in/api/holding/my' => Http::response([
                ['instrumentName' => 'RELIANCE', 'qty' => 25, 'priceAvg' => 2500, 'pl' => 0],
            ], 200),
        ]);

        $result = app(PositionReconciliationService::class)->reconcileAccount($this->account);

        $this->assertSame('mismatched', $result['status']);
        $this->assertSame('RELIANCE', $result['mismatches'][0]['symbol']);
        $this->assertTrue(TradingConfig::get('system.trading_halted'));
        $this->assertSame('reconciliation_mismatch', TradingConfig::get('system.halt_reason'));
        $this->assertSame(1, ErrorLog::where('code', 'position_mismatch')->count());
        $this->assertSame(1, SystemEvent::where('action', 'reconciliation_mismatch')->count());
    }

    public function test_an_unexpected_broker_position_not_in_the_db_is_a_mismatch(): void
    {
        Http::fake([
            'https://api.megabull.in/api/position/my' => Http::response([], 200),
            'https://api.megabull.in/api/holding/my' => Http::response([
                ['instrumentName' => 'TCS', 'qty' => 5, 'priceAvg' => 3500, 'pl' => 0],
            ], 200),
        ]);

        $result = app(PositionReconciliationService::class)->reconcileAccount($this->account);

        $this->assertSame('mismatched', $result['status']);
    }

    public function test_unreachable_broker_is_reported_without_halting(): void
    {
        Http::fake([
            'https://api.megabull.in/*' => Http::response(['error' => 'upstream'], 500),
        ]);

        $result = app(PositionReconciliationService::class)->reconcileAccount($this->account);

        $this->assertSame('unreachable', $result['status']);
        $this->assertFalse(TradingConfig::get('system.trading_halted'));
        $this->assertSame(1, ErrorLog::where('code', 'broker_unreachable')->count());
    }

    public function test_reconcile_all_skips_accounts_without_an_external_broker(): void
    {
        TradingAccount::factory()->create(['mode' => 'paper', 'broker_id' => null]);

        Http::fake([
            'https://api.megabull.in/api/position/my' => Http::response([], 200),
            'https://api.megabull.in/api/holding/my' => Http::response([], 200),
        ]);

        $result = app(PositionReconciliationService::class)->reconcileAll();

        // Only the MegaBull-connected account from setUp is checked.
        $this->assertSame(1, $result['checked']);
    }
}
