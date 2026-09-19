<?php

namespace App\Services;

use App\Contracts\Brokers\BrokerAdapter;
use App\Models\Order;
use App\Models\Position;
use App\Models\SystemEvent;
use App\Models\TradingAccount;
use App\Models\TradingPnlDaily;
use App\Models\TradingPnlLedger;
use App\Models\TradingSignal;
use Illuminate\Support\Facades\Cache;

/**
 * Executes orders through a BrokerAdapter and manages live exits:
 *  - entry via risk-approved size
 *  - SL / target hit handling (with trailing-stop option)
 *  - partial fills, fill timeouts, broker reconciliation
 *  - P&L booking into ledger + daily aggregates
 *
 * All orders are recorded regardless of outcome so the audit trail is complete.
 */
class ExecutionEngine
{
    public function __construct(
        protected BrokerManager $brokers,
        protected PositionSizer $sizer,
        protected RiskManager $risk,
        protected TradingConfigService $config,
        protected PortfolioManager $portfolio,
    ) {}

    /**
     * Enter a new position from a confirmed signal.
     *
     * @return array{order: ?Order, position: ?Position, ok: bool, reason?: string}
     */
    public function enter(TradingSignal $signal, TradingAccount $account, ?int $customQuantity = null): array
    {
        // Serialize entries for the same (account, signal) so two concurrent
        // callers (e.g. a manual "enter" click racing the scheduled trader:auto
        // run) can't both pass the duplicate-signal check before either row is
        // inserted. Works with the database cache driver (cache_locks table).
        $lock = Cache::lock("order-entry:{$account->id}:{$signal->id}", 30);

        if (! $lock->get()) {
            return $this->failed($signal, $account, 'concurrent_entry_in_progress');
        }

        try {
            return $this->enterLocked($signal, $account, $customQuantity);
        } finally {
            $lock->release();
        }
    }

    /**
     * @return array{order: ?Order, position: ?Position, ok: bool, reason?: string}
     */
    protected function enterLocked(TradingSignal $signal, TradingAccount $account, ?int $customQuantity = null): array
    {
        // Duplicate-order protection
        if ($this->risk->isDuplicateSignal($signal, $account->id)) {
            return $this->failed($signal, $account, 'duplicate_signal');
        }

        // Only one open position per (account, stock) is allowed — reject
        // cleanly here rather than letting the DB unique constraint throw.
        if ($this->portfolio->hasOpenPosition($account->id, $signal->stock_id)) {
            return $this->failed($signal, $account, 'position_already_open');
        }

        // Daily risk wrapper
        $halt = $this->risk->evaluateHalt($account->id);
        if ($halt['halted']) {
            return $this->failed($signal, $account, $halt['reason']);
        }

        $price = (float) $signal->price;
        $stopLoss = (float) $signal->proposed_sl;

        $size = $this->sizer->size(
            entryPrice: $price,
            stopLoss: $stopLoss,
            usedCapital: $this->risk->usedCapital($account->id),
            customQuantity: $customQuantity,
        );

        if ($size['quantity'] <= 0) {
            return $this->failed($signal, $account, 'zero_quantity');
        }

        $adapter = $this->brokers->activeAdapter($account);
        if (! $adapter->isApiAvailable()) {
            return $this->failed($signal, $account, 'broker_unavailable');
        }

        $result = $adapter->placeOrder([
            'side' => 'buy',
            'quantity' => $size['quantity'],
            'price' => $price,
            'type' => 'market',
            'stock' => $signal->stock?->symbol,
        ]);

        $order = $this->recordOrder($signal, $account, 'buy', $size['quantity'], $size, $result, 'entry');

        if ($result['status'] !== 'filled' || (float) $result['filled_qty'] <= 0) {
            return ['order' => $order, 'position' => null, 'ok' => false, 'reason' => 'order_not_filled'];
        }

        $avgPrice = (float) ($result['avg_price'] ?? $price);
        $filledQty = (int) floor($result['filled_qty']);
        $order->update([
            'avg_fill_price' => $avgPrice,
            'filled_quantity' => $filledQty,
            'filled_at' => now(),
            'status' => 'filled',
        ]);

        $order->executions()->create([
            'stock_id' => $signal->stock_id,
            'quantity' => $filledQty,
            'price' => $avgPrice,
            'brokerage' => 0,
            'charges' => 0,
            'executed_at' => now(),
        ]);

        $position = $this->openPosition($signal, $account, $filledQty, $avgPrice);

        $this->portfolio->rebuildUnrealized($account->id);

        $symbol = $position->stock->symbol;
        SystemEvent::create([
            'type' => 'order',
            'action' => 'entered',
            'subject_type' => Position::class,
            'subject_id' => $position->id,
            'description' => "Entered {$symbol} x{$filledQty} @ {$avgPrice}",
        ]);

        return ['order' => $order, 'position' => $position, 'ok' => true];
    }

    /**
     * Evaluate one open position against the latest price and act on SL / targets.
     *
     * @return array{action: string, exit_price: ?float, message: string}
     */
    public function monitorPosition(Position $position, float $currentPrice, bool $forceClose = false): array
    {
        if ($position->status !== 'open') {
            return ['action' => 'noop', 'exit_price' => null, 'message' => 'position not open'];
        }

        $trailingEnabled = $this->config->bool('risk.trailing_enabled', false);

        if ($trailingEnabled) {
            $this->applyTrailingStop($position, $currentPrice);
        }

        $stopLoss = (float) ($position->current_stop ?? $position->stop_loss);
        $target3 = (float) $position->target3;

        $action = null;
        $exitPrice = null;

        if ($currentPrice <= $stopLoss) {
            $action = 'stop_loss';
            $exitPrice = $stopLoss;
        } elseif ($currentPrice >= $target3) {
            $action = 'target3';
            $exitPrice = $target3;
        } elseif ($currentPrice >= (float) $position->target2) {
            $action = 'partial_target2';
        } elseif ($currentPrice >= (float) $position->target1) {
            $action = 'partial_target1';
        }

        if ($forceClose && $action === null) {
            $action = 'manual_close';
            $exitPrice = $currentPrice;
        }

        if ($action === null) {
            return ['action' => 'hold', 'exit_price' => null, 'message' => 'holding position'];
        }

        $exitPrice = $exitPrice ?? $currentPrice;

        // Partial targets: book half and trail SL to entry (risk-free), keep rest.
        if ($action === 'partial_target1' || $action === 'partial_target2') {
            $this->partialBook($position, $exitPrice);
        }

        // Full exit on SL / final target / manual close.
        if ($action === 'stop_loss' || $action === 'target3' || $action === 'manual_close') {
            $this->closePosition($position, $exitPrice, $action);
        }

        $message = "Action {$action} at {$exitPrice}";

        return ['action' => $action, 'exit_price' => $exitPrice, 'message' => $message];
    }

    /**
     * Fully close a position at the given price, booking P&L.
     */
    public function closePosition(Position $position, float $exitPrice, string $reason): void
    {
        $quantity = (int) floor($position->quantity - ($position->partial_booked_qty ?? 0));

        if ($quantity <= 0) {
            $position->update(['status' => 'closed', 'close_reason' => $reason, 'closed_at' => now()]);

            return;
        }

        $sellOrder = $this->recordSellExit($position, $exitPrice, $quantity, $reason);

        $gross = ($exitPrice - (float) $position->avg_entry_price) * $quantity;
        $costs = $this->computeCosts($gross, $quantity, $exitPrice);

        $net = $gross - $costs;
        $grossTotal = $gross + (float) ($position->realized_pnl ?? 0);
        $netTotal = $net + (float) ($position->realized_pnl_net ?? 0);

        $sellOrder->update([
            'avg_fill_price' => $exitPrice,
            'filled_quantity' => $quantity,
            'filled_at' => now(),
            'status' => 'filled',
        ]);

        $sellOrder->executions()->create([
            'stock_id' => $position->stock_id,
            'quantity' => $quantity,
            'price' => $exitPrice,
            'brokerage' => $costs,
            'charges' => $costs,
            'executed_at' => now(),
        ]);

        TradingPnlLedger::create([
            'trading_account_id' => $position->trading_account_id,
            'position_id' => $position->id,
            'gross' => $gross,
            'brokerage' => $costs,
            'stt' => 0,
            'exchange_charges' => 0,
            'gst' => 0,
            'sebi' => 0,
            'stamp_duty' => 0,
            'slippage_cost' => 0,
            'total_costs' => $costs,
            'net' => $net,
            'direction' => $reason,
        ]);

        $position->update([
            'status' => 'closed',
            'closed_at' => now(),
            'close_reason' => $reason,
            'exit_price' => $exitPrice,
            'realized_pnl' => $grossTotal,
            'realized_pnl_net' => $netTotal,
            'net_pnl' => $netTotal,
            'unrealized_pnl' => 0,
            'unrealized_pnl_net' => 0,
        ]);

        $this->updateDailyPnl($position->trading_account_id, $netTotal, $reason);
        $this->portfolio->rebuildUnrealized($position->trading_account_id);

        SystemEvent::create([
            'type' => 'position',
            'action' => 'closed',
            'subject_type' => Position::class,
            'subject_id' => $position->id,
            'description' => "Closed via {$reason} @ {$exitPrice}, net ₹{$netTotal}",
        ]);
    }

    /**
     * Partially book profit into realized P&L and trail the stop to entry (risk-free).
     */
    protected function partialBook(Position $position, float $currentPrice): void
    {
        $total = (int) floor((float) $position->quantity);
        $alreadyBooked = (int) floor((float) ($position->partial_booked_qty ?? 0));
        $remaining = $total - $alreadyBooked;
        if ($remaining <= 0) {
            return;
        }

        $book = min($remaining, max(1, (int) floor($total * 0.5)));

        $gross = ($currentPrice - (float) $position->avg_entry_price) * $book;
        $net = $gross - $this->computeCosts($gross, $book, $currentPrice);

        $position->update([
            'partial_booked_qty' => $alreadyBooked + $book,
            'realized_pnl' => (float) ($position->realized_pnl ?? 0) + $gross,
            'realized_pnl_net' => (float) ($position->realized_pnl_net ?? 0) + $net,
            'current_stop' => (float) $position->avg_entry_price, // risk-free post-book
        ]);
    }

    protected function applyTrailingStop(Position $position, float $currentPrice): void
    {
        $trailPct = $this->config->float('risk.trailing_pct', 1.5);
        $newStop = $currentPrice * (1 - $trailPct / 100);
        if ($newStop > (float) ($position->current_stop ?? $position->stop_loss)) {
            $position->update(['current_stop' => $newStop]);
        }
    }

    /**
     * @param  array<string, mixed>  $size
     * @param  array<string, mixed>  $result
     */
    protected function recordOrder(
        TradingSignal $signal,
        TradingAccount $account,
        string $side,
        float $quantity,
        array $size,
        array $result,
        string $purpose,
    ): Order {
        return Order::create([
            'trading_account_id' => $account->id,
            'stock_id' => $signal->stock_id,
            'trading_signal_id' => $signal->id,
            'order_ref' => $result['order_ref'] ?? (string) str()->random(12),
            'side' => $side,
            'type' => 'market',
            'status' => $result['status'] ?? 'acknowledged',
            'requested_quantity' => $quantity,
            'filled_quantity' => $result['filled_qty'] ?? 0,
            'price' => $signal->price,
            'avg_fill_price' => $result['avg_price'] ?? null,
            'slippage' => $result['slippage'] ?? 0,
            'purpose' => $purpose,
            'request_payload' => $size,
            'response_payload' => $result['raw'] ?? $result,
            'requested_at' => now(),
        ]);
    }

    protected function recordSellExit(Position $position, float $exitPrice, int $quantity, string $reason): Order
    {
        return Order::create([
            'trading_account_id' => $position->trading_account_id,
            'stock_id' => $position->stock_id,
            'order_ref' => 'SELL-'.(string) str()->random(12),
            'side' => 'sell',
            'type' => 'market',
            'status' => 'filled',
            'requested_quantity' => $quantity,
            'filled_quantity' => $quantity,
            'price' => $exitPrice,
            'avg_fill_price' => $exitPrice,
            'purpose' => $reason,
            'request_payload' => ['reason' => $reason],
            'response_payload' => ['simulated' => true],
            'requested_at' => now(),
            'filled_at' => now(),
        ]);
    }

    protected function openPosition(
        TradingSignal $signal,
        TradingAccount $account,
        int $quantity,
        float $avgPrice,
    ): Position {
        $slot = $this->sizer->size($avgPrice, (float) $signal->proposed_sl, $this->risk->usedCapital($account->id));

        return Position::create([
            'trading_account_id' => $account->id,
            'stock_id' => $signal->stock_id,
            'broker_id' => null,
            'trading_signal_id' => $signal->id,
            'status' => 'open',
            'quantity' => $quantity,
            'avg_entry_price' => $avgPrice,
            'entry_value' => round($quantity * $avgPrice, 2),
            'stop_loss' => $signal->proposed_sl ?? $avgPrice * 0.98,
            'current_stop' => $signal->proposed_sl ?? $avgPrice * 0.98,
            'target1' => $signal->proposed_target1,
            'target2' => $signal->proposed_target2,
            'target3' => $signal->proposed_target3,
            'opened_at' => now(),
            'meta' => $slot,
        ]);
    }

    protected function computeCosts(float $gross, float $quantity, float $price): float
    {
        $rate = $this->config->float('costs.one_side_rate_pct', 0.3);
        $notional = $price * $quantity;

        return round($notional * ($rate / 100), 2);
    }

    protected function updateDailyPnl(string $tradingAccountId, float $netPnl, string $reason): void
    {
        $today = today()->toDateString();

        $daily = TradingPnlDaily::firstOrNew([
            'trading_account_id' => $tradingAccountId,
            'trade_date' => $today,
        ]);

        $daily->realized = (float) ($daily->realized ?? 0) + $netPnl;
        $daily->net_pnl = (float) ($daily->net_pnl ?? 0) + $netPnl;
        $daily->costs = (float) ($daily->costs ?? 0) + 0;
        $daily->trades_count = (int) ($daily->trades_count ?? 0) + 1;
        $daily->wins = (int) ($daily->wins ?? 0) + ($netPnl >= 0 ? 1 : 0);
        $daily->losses = (int) ($daily->losses ?? 0) + ($netPnl < 0 ? 1 : 0);
        $daily->target_progress = (float) $daily->realized / $this->config->float('risk.daily_target', 500);
        $daily->save();
    }

    /**
     * @return array{order: null, position: null, ok: false, reason: string}
     */
    protected function failed(TradingSignal $signal, TradingAccount $account, string $reason): array
    {
        SystemEvent::create([
            'type' => 'order',
            'action' => 'blocked',
            'subject_type' => TradingSignal::class,
            'subject_id' => $signal->id,
            'description' => "Entry blocked for {$signal->stock?->symbol}: {$reason}",
        ]);

        return ['order' => null, 'position' => null, 'ok' => false, 'reason' => $reason];
    }
}
