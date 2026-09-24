<?php

namespace Database\Factories;

use App\Models\PanCard;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PanCard>
 */
class PanCardFactory extends Factory
{
    protected $model = PanCard::class;

    public function definition(): array
    {
        // Realistic PAN shape: 5 letters, 4 digits, 1 letter.
        return [
            'pan_number' => strtoupper($this->faker->unique()->regexify('[A-Z]{5}[0-9]{4}[A-Z]')),
            'holder_name' => $this->faker->name(),
            'date_of_birth' => $this->faker->date(max: '2004-01-01'),
            'status' => 'unverified',
            'is_primary' => true,
        ];
    }
}
