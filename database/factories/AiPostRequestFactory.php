<?php

namespace Database\Factories;

use App\Models\AiPostRequest;
use App\Models\ZernioAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiPostRequest>
 */
class AiPostRequestFactory extends Factory
{
    protected $model = AiPostRequest::class;

    public function definition(): array
    {
        return [
            'scheduled_date' => $this->faker->dateTimeBetween('now', '+14 days')->format('Y-m-d'),
            'scheduled_time' => '10:00:00',
            'zernio_account_id' => ZernioAccount::factory(),
            'content_category' => 'general',
            'status' => AiPostRequest::STATUS_PENDING,
            'attempts' => 0,
        ];
    }

    public function generated(): static
    {
        return $this->state(fn (): array => [
            'status' => AiPostRequest::STATUS_GENERATED,
            'caption' => $this->faker->sentence(),
            'hashtags' => ['#trading', '#markets'],
            'cta' => 'Learn more in bio',
            'content_type' => 'promotional',
            'gemini_model' => 'gemini-flash-lite-latest',
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (): array => [
            'status' => AiPostRequest::STATUS_FAILED,
            'attempts' => 1,
            'last_error' => 'Gemini request failed.',
        ]);
    }
}
