<?php

namespace Database\Seeders;

use App\Models\Broker;
use App\Models\Stock;
use App\Models\SystemEvent;
use App\Models\TradingAccount;
use App\Models\TradingConfig;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Bootstraps a fresh install into a runnable state (idempotent — safe to re-run):
 *  - strategy config rows + paper broker
 *  - a default user + paper trading account
 *  - the NSE watchlist universe (needs `market:ingest` to load OHLCV bars)
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        TradingConfig::pluckDefaults();

        Broker::updateOrCreate(
            ['slug' => 'paper'],
            [
                'name' => 'Paper Broker',
                'paper' => true,
                'active' => true,
                'credentials' => null,
            ]
        );

        Broker::updateOrCreate(
            ['slug' => 'upstox'],
            [
                'name' => 'Upstox',
                'paper' => false,
                'active' => true,
                'credentials' => null,
            ]
        );

        Broker::updateOrCreate(
            ['slug' => 'zerodha'],
            [
                'name' => 'Zerodha',
                'paper' => false,
                'active' => false,
                'credentials' => null,
            ]
        );

        Broker::updateOrCreate(
            ['slug' => 'angel'],
            [
                'name' => 'Angel One',
                'paper' => false,
                'active' => true,
                'credentials' => null,
            ]
        );

        Broker::updateOrCreate(
            ['slug' => 'kotak'],
            [
                'name' => 'Kotak Neo',
                'paper' => false,
                'active' => true,
                'credentials' => null,
            ]
        );

        Broker::updateOrCreate(
            ['slug' => 'megabull'],
            [
                'name' => 'MegaBull',
                'paper' => true,
                'active' => true,
                'credentials' => null,
            ]
        );

        $user = User::firstOrCreate(
            ['email' => 'dev@bankertrader.local'],
            [
                'name' => 'Dev User',
                'username' => 'dev',
                'email_verified_at' => now(),
                'password' => Hash::make('devpassword'),
            ]
        );

        $this->seedPaperAccount($user);

        User::updateOrCreate(
            ['email' => 'admin@bankertrader.local'],
            [
                'name' => 'Company Admin',
                'username' => 'admin',
                'is_admin' => true,
                'email_verified_at' => now(),
                'password' => Hash::make('adminpassword'),
            ]
        );

        $this->seedWatchlist();
    }

    protected function seedPaperAccount(User $user): void
    {
        if (TradingAccount::where('mode', 'paper')->exists()) {
            return;
        }

        $capital = TradingConfig::get('risk.capital', 100000);

        $account = TradingAccount::create([
            'user_id' => $user->id,
            'name' => 'Paper Trading',
            'starting_capital' => $capital,
            'available_cash' => $capital,
            'invested_amount' => 0,
            'mode' => 'paper',
            'master_enabled' => true,
            'strategy_enabled' => true,
            'started_at' => now(),
        ]);

        SystemEvent::create([
            'type' => 'system',
            'action' => 'paper_account_created',
            'subject_type' => TradingAccount::class,
            'subject_id' => $account->id,
            'description' => "Paper-account seeded with ₹{$capital}",
        ]);
    }

    protected function seedWatchlist(): void
    {
        $stocks = [
            ['RELIANCE', 'Reliance Industries', 'Energy'],
            ['TCS', 'Tata Consultancy Services', 'IT'],
            ['INFY', 'Infosys', 'IT'],
            ['HDFCBANK', 'HDFC Bank', 'Banking'],
            ['ICICIBANK', 'ICICI Bank', 'Banking'],
            ['SBIN', 'State Bank of India', 'Banking'],
            ['MARUTI', 'Maruti Suzuki', 'Automobile'],
            ['ITC', 'ITC', 'FMCG'],
        ];

        foreach ($stocks as [$symbol, $name, $sector]) {
            Stock::updateOrCreate(
                ['symbol' => $symbol],
                [
                    'name' => $name,
                    'exchange' => 'NSE',
                    'active' => true,
                    'in_watchlist' => true,
                    'sector' => $sector,
                    'lot_size' => 1,
                    'under_surveillance' => false,
                    'yfinance_symbol' => $symbol.'.NS',
                ]
            );
        }
    }
}
