<?php

namespace Tests\Feature\Admin;

use App\Models\Order;
use App\Models\TradingAccount;
use App\Models\TradingConfig;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Tests\TestCase;

class TradingSafetyApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
    }

    public function test_safety_routes_are_forbidden_to_non_admin_users(): void
    {
        Passport::actingAs(User::factory()->create());

        $this->getJson('/api/admin/safety/status')->assertForbidden();
        $this->postJson('/api/admin/safety/halt')->assertForbidden();
        $this->postJson('/api/admin/safety/resume')->assertForbidden();
        $this->postJson('/api/admin/safety/cancel-pending', ['confirm' => true])->assertForbidden();
        $this->postJson('/api/admin/safety/emergency-exit', ['confirm' => true])->assertForbidden();
        $this->postJson('/api/admin/safety/reconcile')->assertForbidden();
    }

    public function test_status_reports_the_kill_switch_state(): void
    {
        Passport::actingAs(User::factory()->create(['is_admin' => true]));

        $this->getJson('/api/admin/safety/status')
            ->assertOk()
            ->assertJsonPath('data.trading_halted', false);
    }

    public function test_halt_then_resume_round_trips_the_kill_switch(): void
    {
        Passport::actingAs(User::factory()->create(['is_admin' => true]));

        $this->postJson('/api/admin/safety/halt', ['reason' => 'ops_test'])
            ->assertOk()
            ->assertJsonPath('data.trading_halted', true)
            ->assertJsonPath('data.halt_reason', 'ops_test');

        $this->assertTrue(TradingConfig::get('system.trading_halted'));

        $this->postJson('/api/admin/safety/resume')
            ->assertOk()
            ->assertJsonPath('data.trading_halted', false);

        $this->assertFalse(TradingConfig::get('system.trading_halted'));
    }

    public function test_cancel_pending_requires_explicit_confirmation(): void
    {
        Passport::actingAs(User::factory()->create(['is_admin' => true]));

        $this->postJson('/api/admin/safety/cancel-pending')->assertUnprocessable();
        $this->postJson('/api/admin/safety/cancel-pending', ['confirm' => false])->assertUnprocessable();
    }

    public function test_cancel_pending_cancels_orders_when_confirmed(): void
    {
        Passport::actingAs(User::factory()->create(['is_admin' => true]));

        $account = TradingAccount::factory()->create(['mode' => 'paper', 'broker_id' => null]);
        Order::factory()->create(['trading_account_id' => $account->id, 'status' => 'acknowledged']);

        $this->postJson('/api/admin/safety/cancel-pending', ['confirm' => true])
            ->assertOk()
            ->assertJsonPath('data.cancelled', 1);
    }

    public function test_emergency_exit_requires_explicit_confirmation(): void
    {
        Passport::actingAs(User::factory()->create(['is_admin' => true]));

        $this->postJson('/api/admin/safety/emergency-exit')->assertUnprocessable();
    }

    public function test_reconcile_endpoint_returns_a_summary(): void
    {
        Passport::actingAs(User::factory()->create(['is_admin' => true]));

        $this->postJson('/api/admin/safety/reconcile')
            ->assertOk()
            ->assertJsonStructure(['data' => ['checked', 'mismatched', 'unreachable', 'accounts']]);
    }
}
