<?php

namespace Database\Factories;

use App\Models\PaperTrade;
use App\Models\TradingAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PaperTrade>
 */
class PaperTradeFactory extends Factory
{
    protected $model = PaperTrade::class;

    public function definition(): array
    {
        return [
            'trading_account_id' => TradingAccount::factory(),
            'symbol' => $this->faker->lexify('????'),
            'direction' => 'buy',
            'signal_price' => $this->faker->randomFloat(2, 50, 5000),
            'intended_entry' => $this->faker->randomFloat(2, 50, 5000),
            'fill_price' => $this->faker->randomFloat(2, 50, 5000),
            'quantity' => 20,
            'status' => 'open',
            'signal_at' => now(),
            'executed_at' => now(),
        ];
    }
}
