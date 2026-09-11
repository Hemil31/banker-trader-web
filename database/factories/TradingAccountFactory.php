<?php

namespace Database\Factories;

use App\Models\Broker;
use App\Models\TradingAccount;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TradingAccount>
 */
class TradingAccountFactory extends Factory
{
    protected $model = TradingAccount::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => 'Default',
            'starting_capital' => 100000,
            'available_cash' => 100000,
            'invested_amount' => 0,
            'mode' => 'paper',
            'master_enabled' => false,
            'strategy_enabled' => true,
            'started_at' => null,
            'broker_id' => null,
            'credentials' => null,
        ];
    }

    /**
     * Attach a connected live broker (Upstox) with credentials.
     */
    public function connectedUpstox(): static
    {
        return $this->state(fn (): array => [
            'broker_id' => fn () => Broker::updateOrCreate(
                ['slug' => 'upstox'],
                ['name' => 'Upstox', 'paper' => false, 'active' => true]
            )->id,
            'credentials' => [
                'access_token' => 'fake-upstox-access-token',
                'refresh_token' => 'fake-upstox-refresh-token',
                'connected_at' => now()->toISOString(),
            ],
            'mode' => 'live',
        ]);
    }

    /**
     * Attach a connected live broker (Angel One) with credentials.
     */
    public function connectedAngel(): static
    {
        return $this->state(fn (): array => [
            'broker_id' => fn () => Broker::updateOrCreate(
                ['slug' => 'angel'],
                ['name' => 'Angel One', 'paper' => false, 'active' => true]
            )->id,
            'credentials' => [
                'access_token' => 'fake-angel-auth-token',
                'feed_token' => 'fake-angel-feed-token',
                'client_id' => 'A000000',
                'connected_at' => now()->toISOString(),
            ],
            'mode' => 'live',
        ]);
    }

    /**
     * Attach a connected live broker (Kotak Neo) with credentials.
     */
    public function connectedKotak(): static
    {
        return $this->state(fn (): array => [
            'broker_id' => fn () => Broker::updateOrCreate(
                ['slug' => 'kotak'],
                ['name' => 'Kotak Neo', 'paper' => false, 'active' => true]
            )->id,
            'credentials' => [
                'access_token' => 'fake-kotak-token',
                'sid' => 'fake-kotak-sid',
                'mobile_number' => '+919000000000',
                'ucc' => 'K000000',
                'base_url' => 'https://e2.kotaksecurities.com',
                'connected_at' => now()->toISOString(),
            ],
            'mode' => 'live',
        ]);
    }
}
