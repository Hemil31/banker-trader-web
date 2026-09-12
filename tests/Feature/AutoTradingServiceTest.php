<?php

namespace Tests\Feature;

use App\Models\Position;
use App\Models\Stock;
use App\Models\TradingAccount;
use App\Models\TradingPnlDaily;
use App\Models\TradingSignal;
use App\Models\User;
use App\Services\AutoTradingService;
use App\Services\SignalEngine;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AutoTradingServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);

        // The seeder's own paper account is pre-enrolled; disable it so only
        // the accounts a test creates explicitly are automated.
        TradingAccount::query()->update([
            'master_enabled' => false,
            'strategy_enabled' => false,
        ]);
    }

    public function test_enabled_account_runs_and_enters_a_position(): void
    {
        [$account, $stock, $signal] = $this->givenEnrolledPaperAccount();

        $result = app(AutoTradingService::class)->run(collect([$stock]));

        $this->assertArrayHasKey($account->id, $result);
        $this->assertSame(1, $result[$account->id]['entered']);
        $this->assertNull($result[$account->id]['error']);

        $position = Position::where('trading_account_id', $account->id)
            ->where('status', 'open')
            ->first();
        $this->assertNotNull($position);
        $this->assertSame($signal->id, $position->trading_signal_id);
    }

    public function test_disabled_account_is_skipped(): void
    {
        $account = TradingAccount::factory()->create(['master_enabled' => false]);
        $stock = $this->makeStock();

        $this->app->instance(SignalEngine::class, $this->mockSignals($this->givenSignal($stock)));

        $result = app(AutoTradingService::class)->run(collect([$stock]));

        $this->assertArrayNotHasKey($account->id, $result);
        $this->assertSame(0, Position::where('trading_account_id', $account->id)->count());
    }

    public function test_live_account_without_broker_is_skipped_safely(): void
    {
        $account = TradingAccount::factory()->automated()->create(['mode' => 'live', 'broker_id' => null]);
        $stock = $this->makeStock();

        $result = app(AutoTradingService::class)->run(collect([$stock]));

        $this->assertArrayHasKey($account->id, $result);
        $this->assertStringContainsString('no connected broker', $result[$account->id]['error']);
        $this->assertSame(0, Position::where('trading_account_id', $account->id)->count());
    }

    public function test_per_account_overrides_scale_capital_and_daily_target(): void
    {
        $big = TradingAccount::factory()->create([
            'starting_capital' => 200000,
            'settings' => ['daily_target_pct' => 2.5, 'daily_loss_cap_pct' => 1.5],
        ]);
        $small = TradingAccount::factory()->create([
            'starting_capital' => 50000,
            'settings' => ['daily_target_pct' => 3, 'daily_loss_cap_pct' => 2],
        ]);

        $this->assertSame(200000.0, $big->effectiveCapital());
        $this->assertSame(5000.0, $big->configOverrides()['risk.daily_target']);
        $this->assertSame(3000.0, $big->configOverrides()['risk.daily_loss_cap']);

        $this->assertSame(50000.0, $small->effectiveCapital());
        $this->assertSame(1500.0, $small->configOverrides()['risk.daily_target']);
        $this->assertSame(1000.0, $small->configOverrides()['risk.daily_loss_cap']);
    }

    public function test_per_account_capital_drives_position_size(): void
    {
        [$account, $stock] = $this->givenEnrolledPaperAccount([
            'starting_capital' => 20000,
            'settings' => ['daily_target_pct' => 2.5],
        ]);

        $result = app(AutoTradingService::class)->run(collect([$stock]));

        $this->assertSame(1, $result[$account->id]['entered']);

        // ₹200 risk / ₹2 risk-per-share = 100 qty, but the 20%-per-stock slot
        // (₹4,000 on a ₹20,000 capital) caps it to ₹4,000 / ₹100 = 40 qty.
        $position = Position::where('trading_account_id', $account->id)->where('status', 'open')->first();
        $this->assertSame(40, (int) $position->quantity);
    }

    public function test_reaching_the_daily_target_halts_new_entries(): void
    {
        [$account, $stock] = $this->givenEnrolledPaperAccount([
            'settings' => ['daily_target_pct' => 2], // ₹2,000 on ₹100,000
        ]);

        TradingPnlDaily::create([
            'trading_account_id' => $account->id,
            'trade_date' => today()->toDateString(),
            'realized' => 2000,
            'net_pnl' => 2000,
            'trades_count' => 1,
            'target_progress' => 1,
        ]);

        $result = app(AutoTradingService::class)->run(collect([$stock]));

        $this->assertSame(0, $result[$account->id]['entered']);
        $this->assertSame(1, $result[$account->id]['blocked']);
        $this->assertTrue($result[$account->id]['daily_target_met']);
        $this->assertSame(0, Position::where('trading_account_id', $account->id)->count());
    }

    public function test_automation_processes_multiple_accounts(): void
    {
        [$accountA, $stock] = $this->givenEnrolledPaperAccount();
        $accountB = TradingAccount::factory()->automated()->create([
            'user_id' => $accountA->user_id,
            'starting_capital' => 100000,
            'available_cash' => 100000,
        ]);

        $this->app->instance(SignalEngine::class, $this->mockSignals($this->givenSignal($stock)));

        $result = app(AutoTradingService::class)->run(collect([$stock]));

        $this->assertCount(2, $result);
        $this->assertSame(1, $result[$accountA->id]['entered']);
        $this->assertSame(1, $result[$accountB->id]['entered']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array{0: TradingAccount, 1: Stock, 2: TradingSignal}
     */
    protected function givenEnrolledPaperAccount(array $overrides = []): array
    {
        $user = $this->accountOwner();
        $stock = $this->makeStock();
        $signal = $this->givenSignal($stock);

        $account = TradingAccount::factory()->automated()->create(array_merge([
            'user_id' => $user->id,
            'starting_capital' => 100000,
            'available_cash' => 100000,
        ], $overrides));

        $this->app->instance(SignalEngine::class, $this->mockSignals($signal));

        return [$account, $stock, $signal];
    }

    protected function accountOwner(): User
    {
        return User::create([
            'name' => 'Auto',
            'email' => 'auto@test.local',
            'password' => bcrypt('password'),
        ]);
    }

    protected function makeStock(string $symbol = 'RELIANCE'): Stock
    {
        return Stock::updateOrCreate(
            ['symbol' => $symbol],
            [
                'name' => 'Reliance',
                'exchange' => 'NSE',
                'active' => true,
                'in_watchlist' => true,
            ]
        );
    }

    protected function givenSignal(Stock $stock): TradingSignal
    {
        return TradingSignal::create([
            'stock_id' => $stock->id,
            'signal_date' => today()->toDateString(),
            'price' => 100,
            'score' => 72.75,
            'proposed_sl' => 98,
            'proposed_target1' => 103,
            'proposed_target2' => 104,
            'proposed_target3' => 107,
            'risk_per_share' => 2,
            'reward_per_share' => 3,
            'risk_reward_ratio' => 1.5,
            'entry_reasons' => ['reversal_confirmations' => 2],
            'status' => 'candidate',
        ]);
    }

    protected function mockSignals(TradingSignal $signal): SignalEngine
    {
        $mock = $this->createMock(SignalEngine::class);
        $mock->method('run')->willReturn([
            'generated' => [$signal],
            'rejected' => [],
            'marketOk' => true,
        ]);

        return $mock;
    }
}
