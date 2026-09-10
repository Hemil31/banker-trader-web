<?php

namespace Database\Factories;

use App\Models\TradingAccount;
use App\Models\TradingPnlLedger;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TradingPnlLedger>
 */
class TradingPnlLedgerFactory extends Factory
{
    protected $model = TradingPnlLedger::class;

    public function definition(): array
    {
        return [
            'trading_account_id' => TradingAccount::factory(),
            'gross' => $this->faker->randomFloat(2, -10000, 10000),
            'brokerage' => 0,
            'stt' => 0,
            'exchange_charges' => 0,
            'gst' => 0,
            'sebi' => 0,
            'stamp_duty' => 0,
            'slippage_cost' => 0,
            'total_costs' => 0,
            'net' => $this->faker->randomFloat(2, -10000, 10000),
            'direction' => 'buy',
        ];
    }
}
