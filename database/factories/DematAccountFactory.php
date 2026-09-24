<?php

namespace Database\Factories;

use App\Models\DematAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DematAccount>
 */
class DematAccountFactory extends Factory
{
    protected $model = DematAccount::class;

    public function definition(): array
    {
        return [
            'provider' => $this->faker->randomElement(['nsdl', 'cdsl', 'cdsl_other']),
            'dp_id' => (string) $this->faker->numberBetween(12000000, 12999999),
            'client_id' => str_pad((string) $this->faker->numberBetween(1000000, 99999999), 8, '0', STR_PAD_LEFT),
            'account_name' => $this->faker->name(),
            'upi_id' => strtolower($this->faker->userName()).'@oksbi',
            'status' => 'unverified',
            'is_primary' => true,
        ];
    }
}
