<?php

namespace Database\Factories;

use App\Models\TradingAccount;
use App\Models\TradingPnlDaily;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TradingPnlDaily>
 */
class TradingPnlDailyFactory extends Factory
{
    protected $model = TradingPnlDaily::class;

    public function definition(): array
    {
        return [
            'trading_account_id' => TradingAccount::factory(),
            'trade_date' => $this->faker->date(),
            'realized' => 0,
            'unrealized' => 0,
            'gross_pnl' => 0,
            'costs' => 0,
            'net_pnl' => 0,
            'charges' => 0,
            'trades_count' => 0,
            'wins' => 0,
            'losses' => 0,
            'target_progress' => 0,
        ];
    }
}
