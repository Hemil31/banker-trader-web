<?php

namespace App\Services;

/**
 * Computes buy quantity for a signal under the user's capital rules:
 *  - start capital (risk.capital)
 *  - max 20% per stock (position.max_pct_per_stock, stored as a percentage)
 *  - 60–70% max total exposure (position.max_exposure_pct, stored as a percentage)
 *  - risk-based sizing: risk ₹200/trade, risk per share ₹10 → 20 shares
 *
 * Returns the ceiling-bounded quantity that satisfies allocation AND per-stock
 * concentration limits.
 */
class PositionSizer
{
    public function __construct(protected TradingConfigService $config) {}

    public const DEFAULT_CAPITAL = 100000; // ₹1,00,000

    public const MAX_PCT_PER_STOCK = 0.20; // 20%

    public const MAX_EXPOSURE_PCT = 0.70;  // 70% ceiling

    /**
     * Serve config reads from an in-memory snapshot (used by the backtester).
     *
     * @param  array<string, mixed>  $snapshot
     */
    public function useSnapshot(array $snapshot): self
    {
        $this->config->useSnapshot($snapshot);

        return $this;
    }

    /**
     * @return array{
     *     quantity: int,
     *     allocated: float,
     *     risk_per_share: float,
     *     risk_amount: float,
     *     slot_cap: float,
     *     reasons: array<string>
     * }
     */
    public function size(
        float $entryPrice,
        float $stopLoss,
        float $usedCapital,
        ?int $customQuantity = null,
    ): array {
        $capital = $this->config->float('risk.capital', self::DEFAULT_CAPITAL);

        // 1) Per-stock concentration: 20% of capital.
        $perStockPct = $this->config->float('position.max_pct_per_stock', self::MAX_PCT_PER_STOCK) / 100;
        $slotCap = $capital * $perStockPct;

        // 2) Remaining exposure headroom given currently-used capital.
        $maxExposurePct = $this->config->float('position.max_exposure_pct', self::MAX_EXPOSURE_PCT) / 100;
        $exposureBudget = $capital * $maxExposurePct;
        $freeBudget = max(0, $exposureBudget - $usedCapital);

        $cap = min($slotCap, $freeBudget);

        // 3) Risk-based quantity.
        $riskAmount = $this->config->float('risk.amount_per_trade', 200);
        $riskPerShare = $entryPrice - $stopLoss;
        $riskQty = $riskPerShare > 0 ? (int) floor($riskAmount / $riskPerShare) : 0;

        $priceQty = $entryPrice > 0 ? (int) floor($cap / $entryPrice) : 0;

        $quantity = $riskQty;

        $reasons = ["Risk-based sizing: ₹{$riskAmount} / ₹{$riskPerShare} per share = {$riskQty} qty"];

        // 4) Apply exposure cap (never exceed the slot / free budget).
        if ($quantity > $priceQty) {
            $quantity = $priceQty;
            $reasons[] = "Capped by capital exposure (budget ₹{$cap})";
        }

        // 5) Optional explicit quantity override (rounds down to lot-friendly int).
        if ($customQuantity !== null) {
            $quantity = (int) $customQuantity;
            $reasons[] = "Custom quantity override: {$quantity}";
        }

        $allocated = round($quantity * $entryPrice, 2);

        return [
            'quantity' => $quantity,
            'allocated' => $allocated,
            'risk_per_share' => $riskPerShare,
            'risk_amount' => $riskAmount,
            'slot_cap' => $cap,
            'reasons' => $reasons,
        ];
    }
}
