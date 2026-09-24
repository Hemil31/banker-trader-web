<?php

namespace Database\Factories;

use App\Models\Ipo;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Ipo>
 */
class IpoFactory extends Factory
{
    protected $model = Ipo::class;

    public function definition(): array
    {
        $priceMax = $this->faker->randomElement([90, 148, 362, 405, 424, 500]);

        return [
            'slug' => $this->faker->unique()->slug(2),
            'name' => $this->faker->company(),
            'symbol' => strtoupper($this->faker->unique()->lexify('????')),
            'board' => 'mainboard',
            'status' => 'upcoming',
            'price_min' => $priceMax - 5,
            'price_max' => $priceMax,
            'lot_size' => $this->faker->numberBetween(8, 200),
            'issue_size_cr' => $this->faker->randomFloat(2, 100, 5000),
            'open_date' => now()->addDay()->toDateString(),
            'close_date' => now()->addDays(3)->toDateString(),
            'allotment_date' => now()->addDays(6)->toDateString(),
            'listing_date' => now()->addDays(7)->toDateString(),
            'listing_at' => 'NSE, BSE',
            'face_value' => '₹10 Per Equity Share',
            'registrar' => 'Link Intime India Pvt. Ltd.',
            'synced_at' => now(),
        ];
    }

    public function live(): static
    {
        return $this->state(fn () => [
            'status' => 'live',
            'open_date' => now()->subDay()->toDateString(),
            'close_date' => now()->addDay()->toDateString(),
            'current_subscription' => $this->faker->randomFloat(2, 0.5, 10),
        ]);
    }
}
