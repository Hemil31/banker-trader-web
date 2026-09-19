<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Position;
use App\Models\SystemEvent;
use App\Models\TradingAccount;

/**
 * Platform-level emergency controls:
 *  - halt/resume: the kill switch RiskManager checks before every new entry
 *    (existing positions keep being monitored/exited normally)
 *  - cancel pending orders: best-effort broker-side cancel of every order
 *    that hasn't reached a terminal state
 *  - emergency exit all: force-closes every open position at the latest
 *    stored price, regardless of SL/target
 *
 * All three write a SystemEvent so the action is auditable; cancel/exit are
 * best-effort per row (one broker failure doesn't abort the rest) and always
 * return a per-row breakdown so the caller can see exactly what happened.
 */
class EmergencyControlService
{
    public function __construct(
        protected TradingConfigService $config,
        protected BrokerManager $brokers,
        protected ExecutionEngine $execution,
        protected MarketDataService $marketData,
    ) {}

    public function haltNewOrders(?string $actor = null, string $reason = 'manual_halt'): void
    {
        $this->config->set('system.trading_halted', true, $actor);
        $this->config->set('system.halt_reason', $reason, $actor);

        SystemEvent::create([
            'type' => 'risk',
            'action' => 'trading_halted',
            'actor' => $actor,
            'description' => "Trading halted platform-wide: {$reason}",
            'data' => ['reason' => $reason],
        ]);
    }

    public function resumeNewOrders(?string $actor = null): void
    {
        $this->config->set('system.trading_halted', false, $actor);
        $this->config->set('system.halt_reason', '', $actor);

        SystemEvent::create([
            'type' => 'risk',
            'action' => 'trading_resumed',
            'actor' => $actor,
            'description' => 'Trading resumed platform-wide',
        ]);
    }

    /**
     * Cancel every non-terminal order, optionally scoped to one account.
     *
     * @return array{cancelled: int, failed: int, total: int, details: array<int, array<string, mixed>>}
     */
    public function cancelPendingOrders(?TradingAccount $account = null, ?string $actor = null): array
    {
        $orders = Order::with('tradingAccount')
            ->whereIn('status', ['pending', 'acknowledged', 'partial'])
            ->when($account, fn ($q) => $q->where('trading_account_id', $account->id))
            ->get();

        $cancelled = 0;
        $failed = 0;
        $details = [];

        foreach ($orders as $order) {
            $orderAccount = $order->tradingAccount;
            if (! $orderAccount) {
                $failed++;
                $details[] = ['order_id' => $order->id, 'ok' => false, 'error' => 'account_missing'];

                continue;
            }

            try {
                $result = $this->brokers->activeAdapter($orderAccount)->cancelOrder($order->order_ref);

                if ($result['ok']) {
                    $order->update(['status' => 'cancelled']);
                    $cancelled++;
                    $details[] = ['order_id' => $order->id, 'ok' => true];
                } else {
                    $failed++;
                    $details[] = ['order_id' => $order->id, 'ok' => false, 'error' => $result['message'] ?? 'broker_rejected'];
                }
            } catch (\Throwable $e) {
                $failed++;
                $details[] = ['order_id' => $order->id, 'ok' => false, 'error' => $e->getMessage()];
            }
        }

        SystemEvent::create([
            'type' => 'risk',
            'action' => 'pending_orders_cancelled',
            'actor' => $actor,
            'description' => "Cancelled {$cancelled}/{$orders->count()} pending orders".($account ? " for account {$account->id}" : ' platform-wide'),
            'data' => compact('cancelled', 'failed'),
        ]);

        return ['cancelled' => $cancelled, 'failed' => $failed, 'total' => $orders->count(), 'details' => $details];
    }

    /**
     * Force-close every open position at the latest stored price, optionally
     * scoped to one account. Uses ExecutionEngine::closePosition() directly
     * (not monitorPosition) since SL/target no longer matter once this is
     * called — the intent is to exit now, at whatever price is available.
     *
     * @return array{closed: int, failed: int, total: int, details: array<int, array<string, mixed>>}
     */
    public function emergencyExitAll(?TradingAccount $account = null, ?string $actor = null): array
    {
        $positions = Position::with('stock')
            ->where('status', 'open')
            ->when($account, fn ($q) => $q->where('trading_account_id', $account->id))
            ->get();

        $closed = 0;
        $failed = 0;
        $details = [];

        foreach ($positions as $position) {
            try {
                $price = $position->stock ? $this->marketData->latestClose($position->stock) : null;
                $price ??= (float) $position->avg_entry_price;

                $this->execution->closePosition($position, (float) $price, 'emergency_exit');
                $closed++;
                $details[] = ['position_id' => $position->id, 'ok' => true, 'exit_price' => $price];
            } catch (\Throwable $e) {
                $failed++;
                $details[] = ['position_id' => $position->id, 'ok' => false, 'error' => $e->getMessage()];
            }
        }

        SystemEvent::create([
            'type' => 'risk',
            'action' => 'emergency_exit_all',
            'actor' => $actor,
            'description' => "Force-closed {$closed}/{$positions->count()} open positions".($account ? " for account {$account->id}" : ' platform-wide'),
            'data' => compact('closed', 'failed'),
        ]);

        return ['closed' => $closed, 'failed' => $failed, 'total' => $positions->count(), 'details' => $details];
    }
}
