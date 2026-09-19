<?php

namespace Tests\Feature\Console;

use App\Models\Order;
use App\Models\Position;
use App\Models\Stock;
use App\Models\TradingAccount;
use App\Models\TradingConfig;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TradingSafetyCommandsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
    }

    public function test_halt_and_resume_commands_round_trip_the_kill_switch(): void
    {
        $this->artisan('trader:halt', ['--reason' => 'ops_drill'])->assertSuccessful();

        $this->assertTrue(TradingConfig::get('system.trading_halted'));
        $this->assertSame('ops_drill', TradingConfig::get('system.halt_reason'));

        $this->artisan('trader:resume')->assertSuccessful();

        $this->assertFalse(TradingConfig::get('system.trading_halted'));
    }

    public function test_cancel_pending_command_refuses_without_confirm(): void
    {
        $this->artisan('trader:cancel-pending')->assertFailed();
    }

    public function test_cancel_pending_command_cancels_with_confirm(): void
    {
        $account = TradingAccount::factory()->create(['mode' => 'paper', 'broker_id' => null]);
        Order::factory()->create(['trading_account_id' => $account->id, 'status' => 'acknowledged']);

        $this->artisan('trader:cancel-pending', ['--confirm' => true])->assertSuccessful();

        $this->assertSame('cancelled', Order::first()->status);
    }

    public function test_emergency_exit_command_refuses_without_confirm(): void
    {
        $this->artisan('trader:emergency-exit')->assertFailed();
    }

    public function test_emergency_exit_command_closes_positions_with_confirm(): void
    {
        $account = TradingAccount::factory()->create(['mode' => 'paper', 'broker_id' => null]);
        $stock = Stock::factory()->create();
        $position = Position::factory()->create([
            'trading_account_id' => $account->id,
            'stock_id' => $stock->id,
            'status' => 'open',
        ]);

        $this->artisan('trader:emergency-exit', ['--confirm' => true])->assertSuccessful();

        $this->assertSame('closed', $position->fresh()->status);
    }

    public function test_reconcile_command_passes_when_no_accounts_have_an_external_broker(): void
    {
        // The seeded default account is paper-only (no broker connected).
        $this->artisan('trader:reconcile')->assertSuccessful();
    }
}
