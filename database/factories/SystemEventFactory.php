<?php

namespace Database\Factories;

use App\Models\SystemEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SystemEvent>
 */
class SystemEventFactory extends Factory
{
    protected $model = SystemEvent::class;

    public function definition(): array
    {
        return [
            'type' => 'system',
            'action' => 'created',
            'actor' => 'system',
            'description' => $this->faker->sentence(),
            'data' => [],
            'occurred_at' => now(),
        ];
    }
}
