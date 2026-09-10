<?php

namespace Database\Factories;

use App\Models\Broker;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Broker>
 */
class BrokerFactory extends Factory
{
    protected $model = Broker::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->company(),
            'slug' => 'paper',
            'is_active' => true,
            'meta' => [],
        ];
    }
}
