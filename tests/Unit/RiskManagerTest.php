<?php

namespace Tests\Unit;

use App\Models\Order;
use App\Models\Position;
use App\Models\TradingAccount;
use App\Models\TradingConfig;
use App\Models\TradingPnlDaily;
use App\Models\TradingSignal;
use App\Services\RiskManager;
use App\Services\TradingConfigService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RiskManagerTest extends TestCase
{
    use RefreshDatabase;

    private RiskManager $risk;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
        $this->risk = app(RiskManager::class);
    }

    public function test_no_halt_when_all_limits_are_clear(): void
    {
        $account = TradingAccount::factory()->create();

        $result = $this->risk->evaluateHalt($account->id);

        $this->assertFalse($result['halted']);
        $this->assertNull($result['reason']);
    }

    public function test_halts_on_daily_loss_cap(): void
    {
        $account = TradingAccount::factory()->create();

        TradingPnlDaily::factory()->create([
            'trading_account_id' => $account->id,
            'trade_date' => today(),
            'realized' => -600, // default cap is ₹500
        ]);

        $result = $this->risk->evaluateHalt($account->id);

        $this->assertTrue($result['halted']);
        $this->assertSame('daily_loss_cap', $result['reason']);
    }

    public function test_halts_once_daily_target_is_reached(): void
    {
        $account = TradingAccount::factory()->create();

        TradingPnlDaily::factory()->create([
            'trading_account_id' => $account->id,
            'trade_date' => today(),
            'realized' => 600, // default target is ₹500
        ]);

        $result = $this->risk->evaluateHalt($account->id);

        $this->assertTrue($result['halted']);
        $this->assertSame('daily_target_reached', $result['reason']);
    }

    public function test_halts_at_max_trades_per_day(): void
    {
        $account = TradingAccount::factory()->create();

        TradingPnlDaily::factory()->create([
            'trading_account_id' => $account->id,
            'trade_date' => today(),
            'realized' => 0,
            'trades_count' => 5, // default cap
        ]);

        $result = $this->risk->evaluateHalt($account->id);

        $this->assertTrue($result['halted']);
        $this->assertSame('max_trades_reached', $result['reason']);
    }

    public function test_halts_at_max_open_positions(): void
    {
        $account = TradingAccount::factory()->create();

        Position::factory()->count(10)->create([ // default cap
            'trading_account_id' => $account->id,
            'status' => 'open',
        ]);

        $result = $this->risk->evaluateHalt($account->id);

        $this->assertTrue($result['halted']);
        $this->assertSame('max_open_positions_reached', $result['reason']);
    }

    public function test_does_not_halt_below_max_open_positions(): void
    {
        $account = TradingAccount::factory()->create();

        Position::factory()->count(9)->create([
            'trading_account_id' => $account->id,
            'status' => 'open',
        ]);

        $result = $this->risk->evaluateHalt($account->id);

        $this->assertFalse($result['halted']);
    }

    public function test_max_open_positions_cap_is_configurable(): void
    {
        $account = TradingAccount::factory()->create();

        app(TradingConfigService::class)->useOverrides(['risk.max_open_positions' => 2]);

        Position::factory()->count(2)->create([
            'trading_account_id' => $account->id,
            'status' => 'open',
        ]);

        $result = $this->risk->evaluateHalt($account->id);

        $this->assertTrue($result['halted']);
        $this->assertSame('max_open_positions_reached', $result['reason']);
    }

    public function test_system_halt_blocks_every_account_with_its_configured_reason(): void
    {
        $account = TradingAccount::factory()->create();

        TradingConfig::set('system.trading_halted', true);
        TradingConfig::set('system.halt_reason', 'reconciliation_mismatch');

        $result = $this->risk->evaluateHalt($account->id);

        $this->assertTrue($result['halted']);
        $this->assertSame('reconciliation_mismatch', $result['reason']);
    }

    public function test_system_halt_defaults_to_a_generic_reason_when_none_is_set(): void
    {
        $account = TradingAccount::factory()->create();

        TradingConfig::set('system.trading_halted', true);

        $result = $this->risk->evaluateHalt($account->id);

        $this->assertTrue($result['halted']);
        $this->assertSame('system_halted', $result['reason']);
    }

    public function test_is_duplicate_signal_detects_an_existing_live_order(): void
    {
        $account = TradingAccount::factory()->create();
        $signal = TradingSignal::factory()->create();

        Order::factory()->create([
            'trading_account_id' => $account->id,
            'trading_signal_id' => $signal->id,
            'status' => 'filled',
        ]);

        $this->assertTrue($this->risk->isDuplicateSignal($signal, $account->id));
    }

    public function test_is_duplicate_signal_is_false_with_no_prior_order(): void
    {
        $account = TradingAccount::factory()->create();
        $signal = TradingSignal::factory()->create();

        $this->assertFalse($this->risk->isDuplicateSignal($signal, $account->id));
    }
}
