<?php

namespace Database\Factories;

use App\Models\Order;
use App\Models\Stock;
use App\Models\TradingAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Order>
 */
class OrderFactory extends Factory
{
    protected $model = Order::class;

    public function definition(): array
    {
        return [
            'trading_account_id' => TradingAccount::factory(),
            'stock_id' => Stock::factory(),
            'order_ref' => uniqid('ORD-', true),
            'side' => 'buy',
            'type' => 'market',
            'status' => 'filled',
            'requested_quantity' => $this->faker->numberBetween(1, 100),
            'filled_quantity' => $this->faker->numberBetween(1, 100),
            'requested_at' => now(),
        ];
    }
}
