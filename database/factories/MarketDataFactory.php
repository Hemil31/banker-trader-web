<?php

namespace Database\Factories;

use App\Models\MarketData;
use App\Models\Stock;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MarketData>
 */
class MarketDataFactory extends Factory
{
    protected $model = MarketData::class;

    public function definition(): array
    {
        return [
            'stock_id' => Stock::factory(),
            'trade_date' => $this->faker->date(),
            'open' => $this->faker->randomFloat(2, 50, 5000),
            'high' => $this->faker->randomFloat(2, 50, 5000),
            'low' => $this->faker->randomFloat(2, 50, 5000),
            'close' => $this->faker->randomFloat(2, 50, 5000),
            'adjusted_close' => $this->faker->randomFloat(2, 50, 5000),
            'volume' => $this->faker->numberBetween(10000, 10_000_000),
        ];
    }
}
