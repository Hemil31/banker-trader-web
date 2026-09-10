<?php

namespace Database\Factories;

use App\Models\ErrorLog;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ErrorLog>
 */
class ErrorLogFactory extends Factory
{
    protected $model = ErrorLog::class;

    public function definition(): array
    {
        return [
            'level' => 'error',
            'component' => 'engine',
            'code' => 'test',
            'message' => $this->faker->sentence(),
            'context' => [],
            'logged_at' => now(),
        ];
    }
}
