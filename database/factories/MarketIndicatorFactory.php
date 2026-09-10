<?php

namespace Database\Factories;

use App\Models\MarketIndicator;
use App\Models\Stock;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MarketIndicator>
 */
class MarketIndicatorFactory extends Factory
{
    protected $model = MarketIndicator::class;

    public function definition(): array
    {
        return [
            'stock_id' => Stock::factory(),
            'trade_date' => $this->faker->date(),
            'rsi14' => $this->faker->numberBetween(10, 90),
            'atr14' => $this->faker->randomFloat(2, 1, 100),
            'ma5' => $this->faker->randomFloat(2, 50, 5000),
            'ma20' => $this->faker->randomFloat(2, 50, 5000),
            'volume_ratio' => $this->faker->randomFloat(2, 0.5, 3),
            'raw' => [],
        ];
    }
}
