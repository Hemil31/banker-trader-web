<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\ZernioPost;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ZernioPost>
 */
class ZernioPostFactory extends Factory
{
    protected $model = ZernioPost::class;

    public function definition(): array
    {
        return [
            'created_by' => User::factory(),
            'content' => $this->faker->sentence(),
            'publish_now' => true,
            'scheduled_at' => null,
            'timezone' => 'Asia/Kolkata',
            'status' => 'draft',
            'zernio_post_id' => null,
            'idempotency_key' => null,
            'error' => null,
        ];
    }

    public function scheduled(?string $at = null): static
    {
        return $this->state(fn (): array => [
            'publish_now' => false,
            'scheduled_at' => $at ?? now()->addHour(),
            'status' => 'scheduled',
        ]);
    }

    public function published(): static
    {
        return $this->state(fn (): array => [
            'status' => 'published',
            'zernio_post_id' => $this->faker->unique()->numerify('#######'),
            'idempotency_key' => (string) Str::uuid(),
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (): array => [
            'status' => 'failed',
            'error' => $this->faker->sentence(),
        ]);
    }
}
