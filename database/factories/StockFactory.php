<?php

namespace Database\Factories;

use App\Models\Stock;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Stock>
 */
class StockFactory extends Factory
{
    protected $model = Stock::class;

    public function definition(): array
    {
        return [
            'symbol' => strtoupper($this->faker->unique()->lexify('????')),
            'name' => $this->faker->company(),
            'exchange' => 'NSE',
            'active' => true,
            'in_watchlist' => true,
            'sector' => $this->faker->word(),
            'lot_size' => 1,
            'under_surveillance' => false,
            'yfinance_symbol' => strtoupper($this->faker->unique()->lexify('????')).'.NS',
        ];
    }
}
