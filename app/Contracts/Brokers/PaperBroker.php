<?php

namespace App\Contracts\Brokers;

use App\Models\Broker;
use App\Models\TradingAccount;
use App\Services\TradingConfigService;
use Illuminate\Support\Str;

/**
 * Simulated broker with realistic slippage. This is the default execution backend
 * until every test passes and the paper-trading workflow is verified.
 *
 * It applies configurable slippage (backtest.slippage_bps) to the "market" price and
 * fills immediately, mimicking a best-effort market order.
 */
class PaperBroker implements BrokerAdapter
{
    public function __construct(
        protected TradingAccount $account,
        protected Broker $broker,
        protected TradingConfigService $config,
    ) {}

    public function slug(): string
    {
        return 'paper';
    }

    public function placeOrder(array $payload): array
    {
        $side = $payload['side'];
        $quantity = (float) $payload['quantity'];
        $basePrice = (float) ($payload['price'] ?? 0);

        $slippageBps = (float) $this->config->get('backtest.slippage_bps', 5);
        $slippageFactor = 1 + ($slippageBps / 10000) * ($side === 'buy' ? 1 : -1);
        $fillPrice = round($basePrice * $slippageFactor, 2);

        $slippage = $side === 'buy'
            ? $fillPrice - $basePrice
            : $basePrice - $fillPrice;

        return [
            'order_ref' => 'PAPER-'.Str::upper(Str::random(12)),
            'status' => 'filled',
            'filled_qty' => $quantity,
            'avg_price' => $fillPrice,
            'slippage' => round($slippage, 4),
            'raw' => ['simulated' => true, 'slippage_bps' => $slippageBps],
        ];
    }

    public function cancelOrder(string $orderRef): array
    {
        return ['ok' => true, 'message' => 'Simulated cancel'];
    }

    public function getPositions(): array
    {
        return [];
    }

    public function getBalances(): array
    {
        return ['available_cash' => 0, 'invested' => 0];
    }

    public function getOrderStatus(string $orderRef): array
    {
        return ['status' => 'filled', 'filled_qty' => 0, 'avg_price' => 0];
    }

    public function isApiAvailable(): bool
    {
        return true;
    }
}
