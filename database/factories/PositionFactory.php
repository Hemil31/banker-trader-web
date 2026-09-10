<?php

namespace Database\Factories;

use App\Models\Position;
use App\Models\Stock;
use App\Models\TradingAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Position>
 */
class PositionFactory extends Factory
{
    protected $model = Position::class;

    public function definition(): array
    {
        return [
            'trading_account_id' => TradingAccount::factory(),
            'stock_id' => Stock::factory(),
            'status' => 'open',
            'quantity' => 20,
            'avg_entry_price' => $this->faker->randomFloat(2, 50, 5000),
            'entry_value' => $this->faker->randomFloat(2, 1000, 100000),
            'stop_loss' => $this->faker->randomFloat(2, 50, 5000),
            'opened_at' => now(),
        ];
    }
}
