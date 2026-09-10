<?php

namespace App\Contracts\Brokers;

/**
 * Contract every broker adapter must implement.
 *
 * Adding a new broker (Angel, Kotak, etc.) only requires a new implementation of
 * this interface and registering it with the BrokerManager — call sites (Position
 * sizing, ExecutionEngine, Reconciliation) never change.
 *
 * @see PaperBroker  (working simulated execution)
 * @see UpstoxBroker (live Upstox implementation)
 * @see ZerodhaBroker (live scaffold)
 */
interface BrokerAdapter
{
    /**
     * The broker slug (paper, zerodha, upstox, ...).
     */
    public function slug(): string;

    /**
     * Place an order. Returns a normalized order result array:
     * [
     *   'order_ref'   => string,
     *   'status'      => 'acknowledged'|'filled'|'partial',
     *   'filled_qty'  => int/float,
     *   'avg_price'   => float,
     *   'slippage'    => float,
     *   'raw'         => mixed,
     * ]
     *
     * @param  array{side: string, quantity: float, price?: float, type?: string, stock?: string, origin?: string}  $payload
     * @return array<string, mixed>
     */
    public function placeOrder(array $payload): array;

    /**
     * Cancel an open order.
     *
     * @return array{ok: bool, message?: string}
     */
    public function cancelOrder(string $orderRef): array;

    /**
     * Fetch current positions from the broker.
     *
     * @return array<int, array{symbol: string, quantity: float, avg_price: float, pnl?: float}>
     */
    public function getPositions(): array;

    /**
     * Fetch current balances (free cash / margin available).
     *
     * @return array{available_cash: float, invested: float}
     */
    public function getBalances(): array;

    /**
     * Order status (used for reconciliation / timeouts).
     *
     * @return array{status: string, filled_qty: float, avg_price: float}
     */
    public function getOrderStatus(string $orderRef): array;

    /**
     * Whether the broker API is reachable.
     */
    public function isApiAvailable(): bool;
}
