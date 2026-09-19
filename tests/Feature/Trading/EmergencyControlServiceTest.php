<?php

namespace Tests\Feature\Trading;

use App\Models\MarketData;
use App\Models\Order;
use App\Models\Position;
use App\Models\Stock;
use App\Models\SystemEvent;
use App\Models\TradingAccount;
use App\Models\TradingConfig;
use App\Services\EmergencyControlService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmergencyControlServiceTest extends TestCase
{
    use RefreshDatabase;

    private EmergencyControlService $emergency;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
        $this->emergency = app(EmergencyControlService::class);
    }

    public function test_halt_sets_the_kill_switch_and_logs_an_event(): void
    {
        $this->emergency->haltNewOrders(actor: 'tester', reason: 'manual_halt');

        $this->assertTrue(TradingConfig::get('system.trading_halted'));
        $this->assertSame('manual_halt', TradingConfig::get('system.halt_reason'));
        $this->assertSame(1, SystemEvent::where('action', 'trading_halted')->count());
    }

    public function test_resume_clears_the_kill_switch(): void
    {
        $this->emergency->haltNewOrders(actor: 'tester');
        $this->emergency->resumeNewOrders(actor: 'tester');

        $this->assertFalse(TradingConfig::get('system.trading_halted'));
        $this->assertSame('', TradingConfig::get('system.halt_reason'));
        $this->assertSame(1, SystemEvent::where('action', 'trading_resumed')->count());
    }

    public function test_cancel_pending_orders_cancels_via_the_broker_and_updates_status(): void
    {
        $account = TradingAccount::factory()->create(['mode' => 'paper', 'broker_id' => null]);

        $order = Order::factory()->create([
            'trading_account_id' => $account->id,
            'status' => 'acknowledged',
        ]);

        $result = $this->emergency->cancelPendingOrders(actor: 'tester');

        $this->assertSame(1, $result['cancelled']);
        $this->assertSame(0, $result['failed']);
        $this->assertSame('cancelled', $order->fresh()->status);
    }

    public function test_cancel_pending_orders_can_be_scoped_to_one_account(): void
    {
        $account1 = TradingAccount::factory()->create(['mode' => 'paper', 'broker_id' => null]);
        $account2 = TradingAccount::factory()->create(['mode' => 'paper', 'broker_id' => null]);

        $order1 = Order::factory()->create(['trading_account_id' => $account1->id, 'status' => 'acknowledged']);
        $order2 = Order::factory()->create(['trading_account_id' => $account2->id, 'status' => 'acknowledged']);

        $result = $this->emergency->cancelPendingOrders($account1, actor: 'tester');

        $this->assertSame(1, $result['total']);
        $this->assertSame('cancelled', $order1->fresh()->status);
        $this->assertSame('acknowledged', $order2->fresh()->status);
    }

    public function test_cancel_pending_orders_ignores_terminal_orders(): void
    {
        $account = TradingAccount::factory()->create(['mode' => 'paper', 'broker_id' => null]);
        Order::factory()->create(['trading_account_id' => $account->id, 'status' => 'filled']);
        Order::factory()->create(['trading_account_id' => $account->id, 'status' => 'cancelled']);

        $result = $this->emergency->cancelPendingOrders(actor: 'tester');

        $this->assertSame(0, $result['total']);
    }

    public function test_emergency_exit_all_closes_open_positions_at_the_latest_price(): void
    {
        $account = TradingAccount::factory()->create(['mode' => 'paper', 'broker_id' => null]);
        $stock = Stock::factory()->create();

        MarketData::create([
            'stock_id' => $stock->id, 'trade_date' => today(),
            'open' => 110, 'high' => 110, 'low' => 110, 'close' => 110, 'volume' => 100000, 'adjusted_close' => 110,
        ]);

        $position = Position::factory()->create([
            'trading_account_id' => $account->id,
            'stock_id' => $stock->id,
            'status' => 'open',
            'quantity' => 10,
            'avg_entry_price' => 100,
            'entry_value' => 1000,
        ]);

        $result = $this->emergency->emergencyExitAll(actor: 'tester');

        $this->assertSame(1, $result['closed']);
        $position->refresh();
        $this->assertSame('closed', $position->status);
        $this->assertSame('emergency_exit', $position->close_reason);
        $this->assertSame(110.0, (float) $position->exit_price);
        $this->assertSame(1, SystemEvent::where('action', 'emergency_exit_all')->count());
    }

    public function test_emergency_exit_all_falls_back_to_entry_price_without_market_data(): void
    {
        $account = TradingAccount::factory()->create(['mode' => 'paper', 'broker_id' => null]);
        $stock = Stock::factory()->create();

        $position = Position::factory()->create([
            'trading_account_id' => $account->id,
            'stock_id' => $stock->id,
            'status' => 'open',
            'quantity' => 5,
            'avg_entry_price' => 200,
            'entry_value' => 1000,
        ]);

        $result = $this->emergency->emergencyExitAll(actor: 'tester');

        $this->assertSame(1, $result['closed']);
        $this->assertSame(200.0, (float) $position->fresh()->exit_price);
    }
}
