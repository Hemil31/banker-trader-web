<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Position;
use App\Models\TradingPnlDaily;
use App\Models\TradingSignal;

/**
 * Enforces the daily risk wrapper around trading:
 *  - daily P&L target (default ₹500) and daily loss cap (default ₹500)
 *  - max trades per day (default 3–5)
 *  - duplicate-order protection (no order twice for the same signal)
 *  - no-martingale rule (no adding to losers)
 *
 * Every decision is surfaced so the execution engine can halt trading.
 */
class RiskManager
{
    public function __construct(protected TradingConfigService $config) {}

    /**
     * @param  string  $tradingAccountId
     * @return array{
     *     halted: bool,
     *     reason: ?string,
     *     metrics: array<string, int|float>
     * }
     */
    public function evaluateHalt($tradingAccountId): array
    {
        $metrics = $this->metrics($tradingAccountId);

        $lossCap = $this->config->float('risk.daily_loss_cap', 500);
        $target = $this->config->float('risk.daily_target', 500);
        $maxTrades = $this->config->int('risk.max_trades_per_day', 5);

        $haltedLoss = -$metrics['realized'] > $lossCap;
        $haltedTrades = $metrics['trades_count'] >= $maxTrades;

        if ($haltedLoss) {
            return ['halted' => true, 'reason' => 'daily_loss_cap', 'metrics' => $metrics];
        }

        if ($haltedTrades) {
            return ['halted' => true, 'reason' => 'max_trades_reached', 'metrics' => $metrics];
        }

        return ['halted' => false, 'reason' => null, 'metrics' => $metrics];
    }

    /**
     * Daily realized P&L + open-trade metrics for an account, in one query.
     *
     * @return array<string, int|float>
     */
    public function metrics(string $tradingAccountId): array
    {
        $today = today()->toDateString();

        $daily = TradingPnlDaily::where('trading_account_id', $tradingAccountId)
            ->where('trade_date', $today)
            ->first();

        $realized = $daily ? (float) $daily->realized : 0;

        $openPositions = Position::where('trading_account_id', $tradingAccountId)
            ->where('status', 'open')
            ->get();

        $invested = $openPositions->sum(fn (Position $p) => (float) $p->entry_value);
        $unrealized = $openPositions->sum(fn (Position $p) => (float) $p->unrealized_pnl);

        return [
            'realized' => (float) $realized,
            'unrealized' => (float) $unrealized,
            'gross_so_far' => (float) $realized + (float) $unrealized,
            'invested' => (float) $invested,
            'open_positions' => $openPositions->count(),
            'trades_count' => $daily ? (int) $daily->trades_count : 0,
            'wins' => $daily ? (int) $daily->wins : 0,
            'losses' => $daily ? (int) $daily->losses : 0,
            'daily_target' => $this->config->float('risk.daily_target', 500),
            'daily_loss_cap' => $this->config->float('risk.daily_loss_cap', 500),
            'max_trades_per_day' => $this->config->int('risk.max_trades_per_day', 5),
        ];
    }

    /**
     * Block duplicate orders for the same signal.
     */
    public function isDuplicateSignal(TradingSignal $signal, string $tradingAccountId): bool
    {
        return Order::where('trading_signal_id', $signal->id)
            ->where('trading_account_id', $tradingAccountId)
            ->whereIn('status', ['acknowledged', 'filled', 'partial'])
            ->exists();
    }

    /**
     * Retrieve realized P&L for the given positions (protected from martingale).
     */
    public function disallowAveragingDown(Position $position): bool
    {
        return (float) $position->unrealized_pnl < 0;
    }

    /**
     * Current capital used by open positions' entry values.
     */
    public function usedCapital(string $tradingAccountId): float
    {
        return (float) Position::where('trading_account_id', $tradingAccountId)
            ->where('status', 'open')
            ->sum('entry_value');
    }
}
