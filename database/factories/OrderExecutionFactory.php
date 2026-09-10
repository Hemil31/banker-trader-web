<?php

namespace Database\Factories;

use App\Models\Order;
use App\Models\OrderExecution;
use App\Models\Stock;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrderExecution>
 */
class OrderExecutionFactory extends Factory
{
    protected $model = OrderExecution::class;

    public function definition(): array
    {
        return [
            'order_id' => Order::factory(),
            'stock_id' => Stock::factory(),
            'quantity' => $this->faker->numberBetween(1, 100),
            'price' => $this->faker->randomFloat(2, 50, 5000),
            'brokerage' => 0,
            'charges' => 0,
            'executed_at' => now(),
        ];
    }
}
