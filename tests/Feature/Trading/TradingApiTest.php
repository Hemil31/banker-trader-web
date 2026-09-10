<?php

namespace Tests\Feature\Trading;

use App\Models\PaperTrade;
use App\Models\Position;
use App\Models\Stock;
use App\Models\TradingConfig;
use App\Models\User;
use App\Services\TradingDashboardService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Tests\TestCase;

class TradingApiTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
        $this->user = User::factory()->create();
    }

    public function test_portfolio_overview_requires_authentication(): void
    {
        $this->getJson('/api/portfolio')->assertUnauthorized();
    }

    public function test_portfolio_overview_returns_account_and_summary(): void
    {
        Passport::actingAs($this->user);

        $response = $this->getJson('/api/portfolio');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'data' => [
                    'account' => ['name', 'mode', 'starting_capital', 'available_cash'],
                    'portfolio' => ['invested', 'unrealized', 'realized', 'net_equity', 'open_positions_count'],
                    'open_positions',
                    'recent_signals',
                    'recent_paper_trades',
                ],
            ]);
    }

    public function test_positions_endpoint_filters_by_status(): void
    {
        $stock = $this->makeStock();
        $account = $this->service()->account($this->user);

        Passport::actingAs($this->user);

        Position::create([
            'trading_account_id' => $account->id,
            'stock_id' => $stock->id,
            'status' => 'open',
            'quantity' => 10,
            'avg_entry_price' => 100,
            'entry_value' => 1000,
            'opened_at' => now(),
        ]);

        $this->getJson('/api/positions?status=open')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.symbol', 'RELIANCE');

        $this->getJson('/api/positions?status=closed')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_config_index_lists_only_editable_keys(): void
    {
        Passport::actingAs($this->user);

        $response = $this->getJson('/api/trading/config');

        $response->assertOk();

        $keys = collect($response->json('data'))->pluck('key');
        $this->assertContains('risk.capital', $keys);
        $this->assertTrue($keys->every(fn ($key) => TradingConfig::where('key', $key)->first()->is_editable));
    }

    public function test_config_update_accepts_numeric_value_and_persists_it(): void
    {
        Passport::actingAs($this->user);

        $this->patchJson('/api/trading/config', [
            'key' => 'risk.capital',
            'value' => '150000',
        ])->assertOk()->assertJsonPath('data.value', 150000);

        $this->assertSame(150000.0, TradingConfig::get('risk.capital'));
    }

    public function test_config_update_rejects_unknown_keys(): void
    {
        Passport::actingAs($this->user);

        $this->patchJson('/api/trading/config', [
            'key' => 'does.not.exist',
            'value' => '1',
        ])->assertUnprocessable();
    }

    public function test_paper_run_triggers_a_session_and_reports_counts(): void
    {
        Passport::actingAs($this->user);

        $response = $this->postJson('/api/trading/run');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'data' => ['signals_generated', 'market_ok', 'entered', 'blocked', 'monitored', 'exits', 'portfolio'],
            ]);
    }

    public function test_paper_trades_index_returns_persisted_ledger_rows(): void
    {
        Passport::actingAs($this->user);

        $this->makeStock();

        PaperTrade::create([
            'trading_account_id' => $this->service()->account($this->user)->id,
            'symbol' => 'RELIANCE',
            'direction' => 'buy',
            'fill_price' => 100,
            'quantity' => 10,
            'status' => 'open',
            'executed_at' => now(),
        ]);

        $this->getJson('/api/paper-trades')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    private function makeStock(): Stock
    {
        return Stock::updateOrCreate(
            ['symbol' => 'RELIANCE'],
            [
                'name' => 'Reliance Industries',
                'exchange' => 'NSE',
                'active' => true,
                'in_watchlist' => true,
            ]
        );
    }

    private function service(): TradingDashboardService
    {
        return $this->app->make(TradingDashboardService::class);
    }
}
