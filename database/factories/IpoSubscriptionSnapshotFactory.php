<?php

namespace Database\Factories;

use App\Models\Ipo;
use App\Models\IpoSubscriptionSnapshot;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IpoSubscriptionSnapshot>
 */
class IpoSubscriptionSnapshotFactory extends Factory
{
    protected $model = IpoSubscriptionSnapshot::class;

    public function definition(): array
    {
        return [
            'ipo_id' => Ipo::factory(),
            'as_on' => now()->setTime(17, 0),
            'qib' => $this->faker->randomFloat(2, 0, 5),
            'nii' => $this->faker->randomFloat(2, 0, 5),
            'retail' => $this->faker->randomFloat(2, 0, 5),
            'total' => $this->faker->randomFloat(2, 0, 5),
        ];
    }
}
