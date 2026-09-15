<?php

namespace Database\Factories;

use App\Models\ZernioAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ZernioAccount>
 */
class ZernioAccountFactory extends Factory
{
    protected $model = ZernioAccount::class;

    public function definition(): array
    {
        return [
            'zernio_account_id' => $this->faker->unique()->numerify('#####'),
            'platform' => $this->faker->randomElement(['instagram', 'twitter', 'facebook', 'linkedin', 'youtube', 'tiktok']),
            'name' => $this->faker->company(),
            'username' => '@'.$this->faker->userName(),
            'avatar_url' => $this->faker->imageUrl(),
            'is_active' => true,
            'needs_reconnection' => false,
            'raw_payload' => [],
            'synced_at' => now(),
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }

    public function needsReconnection(): static
    {
        return $this->state(fn (): array => ['needs_reconnection' => true]);
    }
}
