<?php

namespace Tests\Feature\Trading;

use App\Models\MarketData;
use App\Models\Position;
use App\Models\Stock;
use App\Models\TradingSignal;
use App\Models\User;
use App\Services\ExecutionEngine;
use App\Services\PaperTradingService;
use App\Services\SignalEngine;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression coverage for the positions.(trading_account_id, stock_id, status)
 * unique-constraint bug: every closed position kept status='closed', so a
 * second closed row for the same account+stock used to violate the unique
 * key. Migration 2026_09_12_100002 drops that constraint (a generated-column
 * replacement was tried but MySQL/InnoDB refuses to add a stored generated
 * column to a table with existing foreign keys). "At most one open position
 * per account+stock" is now enforced at the application level instead
 * (ExecutionEngine::enterLocked -> PortfolioManager::hasOpenPosition), which
 * this test also covers.
 */
class PositionReentryTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Stock $stock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);

        $this->user = User::create([
            'name' => 'Reentry',
            'email' => 'reentry@test.local',
            'password' => bcrypt('password'),
        ]);

        $this->stock = Stock::updateOrCreate(
            ['symbol' => 'RELIANCE'],
            ['name' => 'Reliance', 'exchange' => 'NSE', 'active' => true, 'in_watchlist' => true]
        );
    }

    public function test_a_stock_can_be_round_tripped_twice_without_a_db_constraint_violation(): void
    {
        // Round trip #1: enter, then stop out.
        $this->runWith($this->makeSignal('2026-01-01', 100, 98));
        $account = app(PaperTradingService::class)->paperAccount($this->user);
        $position1 = Position::where('trading_account_id', $account->id)->where('stock_id', $this->stock->id)->first();
        $this->assertSame('open', $position1->status);

        MarketData::create([
            'stock_id' => $this->stock->id, 'trade_date' => today(),
            'open' => 97, 'high' => 97, 'low' => 97, 'close' => 97, 'volume' => 100000, 'adjusted_close' => 97,
        ]);
        $this->runWith(null); // monitor-only pass exits position1 at SL

        $position1->refresh();
        $this->assertSame('closed', $position1->status);

        // Round trip #2: same account + same stock, entering again must not
        // throw a QueryException on the old (account, stock, status) unique key.
        $this->runWith($this->makeSignal('2026-01-02', 100, 98));

        $open = Position::where('trading_account_id', $account->id)
            ->where('stock_id', $this->stock->id)
            ->where('status', 'open')
            ->first();

        $this->assertNotNull($open, 'second round-trip opened a fresh position');
        $this->assertSame(
            2,
            Position::where('trading_account_id', $account->id)->where('stock_id', $this->stock->id)->count(),
            'one closed + one open position row for the same account/stock'
        );
    }

    public function test_a_second_open_position_for_the_same_stock_is_rejected_cleanly(): void
    {
        $this->runWith($this->makeSignal('2026-02-01', 100, 98));
        $account = app(PaperTradingService::class)->paperAccount($this->user);
        $this->assertSame(1, Position::where('trading_account_id', $account->id)->where('status', 'open')->count());

        // A second, distinct signal for the same still-open stock must be
        // rejected with a clean reason, not a DB exception.
        $secondSignal = $this->makeSignal('2026-02-02', 101, 99);
        $result = app(ExecutionEngine::class)->enter($secondSignal, $account);

        $this->assertFalse($result['ok']);
        $this->assertSame('position_already_open', $result['reason'] ?? null);
        $this->assertSame(1, Position::where('trading_account_id', $account->id)->where('status', 'open')->count());
    }

    private function makeSignal(string $date, float $price, float $sl): TradingSignal
    {
        return TradingSignal::create([
            'stock_id' => $this->stock->id,
            'signal_date' => $date,
            'price' => $price,
            'score' => 72.75,
            'proposed_sl' => $sl,
            'proposed_target1' => $price + 3,
            'proposed_target2' => $price + 4,
            'proposed_target3' => $price + 7,
            'risk_per_share' => $price - $sl,
            'reward_per_share' => 3,
            'risk_reward_ratio' => 1.5,
            'entry_reasons' => ['reversal_confirmations' => 2],
            'status' => 'candidate',
        ]);
    }

    private function runWith(?TradingSignal $signal): void
    {
        $mockSignals = $this->createMock(SignalEngine::class);
        $mockSignals->method('run')->willReturn([
            'generated' => $signal ? [$signal] : [],
            'rejected' => [],
            'marketOk' => true,
        ]);
        $this->app->instance(SignalEngine::class, $mockSignals);

        // Re-resolve so this run's PaperTradingService is built with the
        // SignalEngine instance just bound above.
        app(PaperTradingService::class)->runSession(collect([$this->stock]), null, $this->user);
    }
}
