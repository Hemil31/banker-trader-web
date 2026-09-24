<?php

namespace Database\Factories;

use App\Models\Ipo;
use App\Models\IpoGmpHistory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IpoGmpHistory>
 */
class IpoGmpHistoryFactory extends Factory
{
    protected $model = IpoGmpHistory::class;

    public function definition(): array
    {
        return [
            'ipo_id' => Ipo::factory(),
            'recorded_at' => now()->setTime(10, 50),
            'gmp' => $this->faker->numberBetween(0, 150),
            'premium_pct' => $this->faker->randomFloat(2, 0, 40),
        ];
    }
}
