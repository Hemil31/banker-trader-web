<?php

namespace Tests\Feature\Admin;

use App\Models\Order;
use App\Models\PaperTrade;
use App\Models\Position;
use App\Models\Stock;
use App\Models\TradingAccount;
use App\Models\TradingConfig;
use App\Models\TradingPnlLedger;
use App\Models\TradingSignal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Tests\TestCase;

class AdminUserTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        TradingConfig::pluckDefaults();

        $this->seedUser(); // paper broker, watchlist stock, paper account
    }

    protected function seedUser(): User
    {
        $this->user = User::factory()->create(['email' => 'alice@example.com']);
        $this->stock = Stock::factory()->create(['symbol' => 'RELIANCE']);
        $this->account = TradingAccount::factory()->create([
            'user_id' => $this->user->id,
            'broker_id' => null,
            'mode' => 'paper',
            'starting_capital' => 100000,
            'available_cash' => 90000,
            'invested_amount' => 10000,
        ]);

        return $this->user;
    }

    public function test_admin_sees_user_master_table_with_pnl_and_usage(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        TradingPnlLedger::create([
            'trading_account_id' => $this->account->id,
            'gross' => 1000, 'net' => 800,
        ]);

        PaperTrade::create([
            'trading_account_id' => $this->account->id,
            'symbol' => 'RELIANCE',
            'status' => 'closed',
            'pnl_net' => 500,
        ]);

        PaperTrade::create([
            'trading_account_id' => $this->account->id,
            'symbol' => 'HDFCBANK',
            'status' => 'open',
            'pnl_net' => -200,
        ]);

        Position::create([
            'trading_account_id' => $this->account->id,
            'stock_id' => $this->stock->id,
            'status' => 'open',
            'quantity' => 10,
            'avg_entry_price' => 100,
            'unrealized_pnl_net' => 120,
        ]);

        Order::factory()->create([
            'trading_account_id' => $this->account->id,
            'stock_id' => $this->stock->id,
        ]);

        TradingSignal::factory()->create([
            'trading_account_id' => $this->account->id,
            'stock_id' => $this->stock->id,
        ]);

        Passport::actingAs($admin, ['*']);

        $response = $this->getJson('/api/admin/users');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(2, 'data.users')
            ->assertJsonPath('data.summary.total_realized_pnl_net', 1300);

        $alice = collect($response->json('data.users'))->firstWhere('email', 'alice@example.com');

        $this->assertSame(1, $alice['totals']['accounts']);
        $this->assertSame(1300, $alice['accounts'][0]['realized_pnl_net']);
        $this->assertSame(120, $alice['accounts'][0]['unrealized_pnl_net']);
        $this->assertSame(1, $alice['accounts'][0]['orders_count']);
        $this->assertSame(1, $alice['accounts'][0]['signals_count']);
        $this->assertSame(1, $alice['accounts'][0]['open_positions']);
    }

    public function test_admin_user_detail_returns_recent_paper_trades(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        PaperTrade::create([
            'trading_account_id' => $this->account->id,
            'symbol' => 'RELIANCE',
            'status' => 'closed',
            'pnl_net' => 250,
            'executed_at' => now()->subHour(),
        ]);

        Passport::actingAs($admin, ['*']);

        $this->getJson("/api/admin/users/{$this->user->id}")
            ->assertOk()
            ->assertJsonPath('data.user.id', $this->user->id)
            ->assertJsonCount(1, 'data.recent_paper_trades')
            ->assertJsonPath('data.recent_paper_trades.0.pnl_net', 250);
    }

    public function test_non_admin_is_forbidden_from_admin_endpoints(): void
    {
        Passport::actingAs($this->user, ['*']);

        $this->getJson('/api/admin/users')->assertForbidden();
    }

    public function test_guest_cannot_access_admin_endpoints(): void
    {
        $this->getJson('/api/admin/users')->assertUnauthorized();
    }
}
