<?php

namespace Database\Factories;

use App\Models\Backtest;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Backtest>
 */
class BacktestFactory extends Factory
{
    protected $model = Backtest::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->sentence(3),
            'start_date' => $this->faker->date(),
            'end_date' => $this->faker->date(),
            'starting_capital' => 100000,
            'config_snapshot' => [],
            'meta' => [],
        ];
    }
}
