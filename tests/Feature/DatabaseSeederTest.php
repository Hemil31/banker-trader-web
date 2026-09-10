<?php

namespace Tests\Feature;

use App\Models\Broker;
use App\Models\Stock;
use App\Models\TradingAccount;
use App\Models\TradingConfig;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DatabaseSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_bootstraps_user_account_stocks_and_config(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertDatabaseHas('users', ['email' => 'dev@bankertrader.local']);

        $this->assertDatabaseHas('trading_accounts', [
            'mode' => 'paper',
            'starting_capital' => 100000,
            'available_cash' => 100000,
        ]);

        $this->assertSame(8, Stock::where('in_watchlist', true)->count());

        $this->assertTrue(Stock::where('symbol', 'RELIANCE')->exists());
        $this->assertTrue(Stock::where('symbol', 'HDFCBANK')->exists());

        $this->assertSame(7.0, TradingConfig::get('risk.target3_pct'));

        $this->assertDatabaseHas('brokers', ['slug' => 'paper', 'active' => true]);
    }

    public function test_seeder_is_idempotent(): void
    {
        $this->seed(DatabaseSeeder::class);
        $users = User::count();
        $stocks = Stock::count();
        $accounts = TradingAccount::count();
        $brokers = Broker::count();

        $this->seed(DatabaseSeeder::class);

        $this->assertSame($users, User::count());
        $this->assertSame($stocks, Stock::count());
        $this->assertSame($accounts, TradingAccount::count());
        $this->assertSame($brokers, Broker::count());
    }
}
