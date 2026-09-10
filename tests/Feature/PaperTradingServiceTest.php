<?php

namespace Tests\Feature;

use App\Models\MarketData;
use App\Models\Order;
use App\Models\PaperTrade;
use App\Models\Position;
use App\Models\Stock;
use App\Models\TradingAccount;
use App\Models\TradingSignal;
use App\Models\User;
use App\Services\PaperTradingService;
use App\Services\SignalEngine;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaperTradingServiceTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Stock $stock;

    private TradingSignal $signal;

    private PaperTradingService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);

        $this->user = User::create([
            'name' => 'Paper',
            'email' => 'paper@test.local',
            'password' => bcrypt('password'),
        ]);

        $this->stock = Stock::updateOrCreate(
            ['symbol' => 'RELIANCE'],
            [
                'name' => 'Reliance',
                'exchange' => 'NSE',
                'active' => true,
                'in_watchlist' => true,
            ]
        );

        $this->signal = TradingSignal::create([
            'stock_id' => $this->stock->id,
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

        $mockSignals = $this->createMock(SignalEngine::class);
        $mockSignals->method('run')->willReturn([
            'generated' => [$this->signal],
            'rejected' => [],
            'marketOk' => true,
        ]);
        $this->app->instance(SignalEngine::class, $mockSignals);

        $this->service = $this->app->make(PaperTradingService::class);
    }

    public function test_first_session_enters_a_position_and_mirrors_it_in_the_paper_ledger(): void
    {
        $result = $this->service->runSession(collect([$this->stock]), null, $this->user);

        $this->assertSame(1, $result['entered'], 'one signal entered');
        $this->assertSame(0, $result['exits'], 'fresh positions are not monitored same-session');

        $position = Position::where('stock_id', $this->stock->id)->where('status', 'open')->first();
        $this->assertNotNull($position, 'a position row exists');
        $this->assertSame(100, (int) $position->quantity);

        $this->assertSame(1, Order::where('trading_signal_id', $this->signal->id)->where('side', 'buy')->count());

        $paper = PaperTrade::where('position_id', $position->id)->first();
        $this->assertNotNull($paper, 'paper ledger row mirrors the entry');
        $this->assertSame('open', $paper->status);
        $this->assertSame(100.05, round((float) $paper->fill_price, 2), '5bps buy slippage on ₹100');

        $account = $result['account']->fresh();
        $this->assertGreaterThan(0.0, (float) $account->invested_amount);
        $this->assertLessThan((float) $account->starting_capital, (float) $account->available_cash);
    }

    public function test_subsequent_session_exits_at_stop_loss_and_updates_the_ledger(): void
    {
        $this->service->runSession(collect([$this->stock]), null, $this->user);

        MarketData::create([
            'stock_id' => $this->stock->id,
            'trade_date' => today()->toDateString(),
            'open' => 97,
            'high' => 97,
            'low' => 97,
            'close' => 97,
            'volume' => 100000,
            'adjusted_close' => 97,
        ]);

        $result = $this->service->runSession(collect([$this->stock]), null, $this->user);

        $this->assertSame(1, $result['exits'], 'SL booked on the second session');
        $this->assertSame(0, $result['entered'], 'duplicate signal is blocked on re-entry');

        $position = Position::where('stock_id', $this->stock->id)->first();
        $this->assertSame('closed', $position->status);
        $this->assertSame('stop_loss', $position->close_reason);
        $this->assertLessThan(0.0, (float) $position->net_pnl);

        $paper = PaperTrade::where('position_id', $position->id)->first();
        $this->assertSame('closed', $paper->status);
        $this->assertSame('stop_loss', $paper->exit_reason);
        $this->assertLessThan(0.0, (float) $paper->pnl_net);

        $account = TradingAccount::where('mode', 'paper')->first();
        $this->assertSame(0.0, (float) $account->invested_amount, 'no open capital after exit');
    }
}
