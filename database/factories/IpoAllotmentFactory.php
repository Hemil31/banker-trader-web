<?php

namespace Database\Factories;

use App\Models\IpoAllotment;
use App\Models\IpoApplication;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IpoAllotment>
 */
class IpoAllotmentFactory extends Factory
{
    protected $model = IpoAllotment::class;

    public function definition(): array
    {
        return [
            'ipo_application_id' => IpoApplication::factory(),
            'ipo_id' => fn (array $attrs) => IpoApplication::query()->whereKey((string) $attrs['ipo_application_id'])->firstOrFail()->ipo_id,
            'pan_number' => strtoupper($this->faker->regexify('[A-Z]{5}[0-9]{4}[A-Z]')),
            'result' => 'pending',
            'attempts' => 0,
        ];
    }
}
