<?php

namespace Database\Factories;

use App\Models\Stock;
use App\Models\TradingSignal;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TradingSignal>
 */
class TradingSignalFactory extends Factory
{
    protected $model = TradingSignal::class;

    public function definition(): array
    {
        return [
            'stock_id' => Stock::factory(),
            'signal_date' => $this->faker->date(),
            'price' => $this->faker->randomFloat(2, 50, 5000),
            'score' => $this->faker->numberBetween(50, 95),
            'status' => 'candidate',
        ];
    }
}
