<?php

namespace Tests\Unit;

use App\Services\PositionSizer;
use App\Services\TradingConfigService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PositionSizerTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Regression test: PositionSizer::MAX_PCT_PER_STOCK/MAX_EXPOSURE_PCT are
     * read as percentages (divided by 100 in size()) but used to be stored
     * as fractions (0.20/0.70), which silently collapsed sizing to near-zero
     * whenever the position.* config rows were missing from the DB. This
     * test seeds no config at all, so size() falls back to the class
     * constants directly.
     */
    public function test_default_sizing_falls_back_to_percentages_not_fractions(): void
    {
        // Deliberately not seeding TradingConfig — every read below falls
        // through to the caller-supplied default (the class constants).
        $sizer = app(PositionSizer::class);

        $result = $sizer->size(entryPrice: 1000, stopLoss: 980, usedCapital: 0);

        // capital=100000 (default), slotCap=100000*20%=20000, priceQty=floor(20000/1000)=20
        // riskAmount=200 (default), riskPerShare=20, riskQty=floor(200/20)=10
        // quantity = min(riskQty, priceQty) = 10
        $this->assertSame(10, $result['quantity']);
        $this->assertGreaterThan(0, $result['quantity']);
    }

    public function test_risk_based_sizing_uses_risk_amount_over_risk_per_share(): void
    {
        $this->seed(DatabaseSeeder::class);

        app(TradingConfigService::class)->useOverrides([
            'risk.capital' => 100000,
            'risk.amount_per_trade' => 500,
            'position.max_pct_per_stock' => 20,
            'position.max_exposure_pct' => 70,
        ]);

        $result = app(PositionSizer::class)->size(entryPrice: 100, stopLoss: 90, usedCapital: 0);

        // riskQty = floor(500/10) = 50; slotCap = 20000; priceQty = floor(20000/100) = 200
        $this->assertSame(50, $result['quantity']);
    }

    public function test_exposure_cap_wins_when_it_is_the_tighter_constraint(): void
    {
        $this->seed(DatabaseSeeder::class);

        app(TradingConfigService::class)->useOverrides([
            'risk.capital' => 100000,
            'risk.amount_per_trade' => 500,
            'position.max_pct_per_stock' => 20,
            'position.max_exposure_pct' => 70,
        ]);

        // usedCapital already consumes all but ₹1,000 of the 70% exposure budget.
        $result = app(PositionSizer::class)->size(entryPrice: 100, stopLoss: 90, usedCapital: 69000);

        // freeBudget = 70000-69000 = 1000; cap = min(20000,1000) = 1000; priceQty = floor(1000/100) = 10
        $this->assertSame(10, $result['quantity']);
    }

    public function test_custom_quantity_overrides_computed_sizing(): void
    {
        $this->seed(DatabaseSeeder::class);

        $result = app(PositionSizer::class)->size(entryPrice: 100, stopLoss: 90, usedCapital: 0, customQuantity: 7);

        $this->assertSame(7, $result['quantity']);
    }
}
