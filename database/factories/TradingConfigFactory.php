<?php

namespace Database\Factories;

use App\Models\TradingConfig;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TradingConfig>
 */
class TradingConfigFactory extends Factory
{
    protected $model = TradingConfig::class;

    public function definition(): array
    {
        return [
            'key' => 'test.'.uniqid(),
            'group' => 'general',
            'type' => 'string',
            'value' => null,
            'is_editable' => true,
        ];
    }
}
