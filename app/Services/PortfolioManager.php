<?php

namespace App\Services;

use App\Models\Position;
use Illuminate\Support\Collection;

/**
 * Snapshot of an account's portfolio: open exposure, realized + unrealized P&L.
 */
class PortfolioManager
{
    public function __construct(protected MarketDataService $marketData) {}

    /**
     * Open positions for an account.
     *
     * @return Collection<int, Position>
     */
    public function openPositions(string $tradingAccountId, bool $withStock = true): Collection
    {
        return Position::with($withStock ? 'stock' : [])
            ->where('trading_account_id', $tradingAccountId)
            ->where('status', 'open')
            ->orderByDesc('opened_at')
            ->get();
    }

    /**
     * Recompute unrealized P&L for every open position assuming the current
     * (stored) latest close is the mark-to-market price.
     *
     * @param  array<int, float>|null  $priceMap
     */
    public function rebuildUnrealized(string $tradingAccountId, ?array $priceMap = null): void
    {
        collect($this->openPositions($tradingAccountId, false))->each(function (Position $position) use ($priceMap) {
            $price = $priceMap[$position->stock_id] ?? null;
            if ($price === null) {
                $price = $position->stock ? $this->marketData->latestClose($position->stock) : null;
            }

            if ($price === null) {
                return;
            }

            $remaining = (float) $position->quantity - (float) ($position->partial_booked_qty ?? 0);
            $unrealized = ($price - (float) $position->avg_entry_price) * $remaining;

            $position->update([
                'unrealized_pnl' => round($unrealized, 2),
                'unrealized_pnl_net' => round($unrealized, 2),
            ]);
        });
    }

    /**
     * Aggregated portfolio numbers for an account.
     *
     * @return array{
     *     invested: float,
     *     unrealized: float,
     *     realized: float,
     *     gross_equity: float,
     *     net_equity: float,
     *     open_positions_count: int
     * }
     */
    public function summary(string $tradingAccountId): array
    {
        $open = $this->openPositions($tradingAccountId);
        $invested = $open->sum(fn (Position $p) => (float) $p->entry_value);
        $unrealized = $open->sum(fn (Position $p) => (float) ($p->unrealized_pnl ?? 0));
        $realized = Position::where('trading_account_id', $tradingAccountId)
            ->where('status', 'closed')
            ->sum('net_pnl');

        return [
            'invested' => round($invested, 2),
            'unrealized' => round($unrealized, 2),
            'realized' => round((float) $realized, 2),
            'gross_equity' => round($invested + $unrealized, 2),
            'net_equity' => round($invested + $unrealized + (float) $realized, 2),
            'open_positions_count' => $open->count(),
        ];
    }
}
