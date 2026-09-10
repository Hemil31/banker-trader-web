<?php

namespace App\Contracts\Brokers;

use App\Models\Broker;
use App\Models\TradingAccount;
use App\Services\TradingConfigService;
use RuntimeException;

/**
 * Live Zerodha KiteConnect adapter scaffold.
 *
 * Not wired for execution until credentials are configured and the paper-trading
 * workflow + tests pass. PlaceOrder/cancel throw so the app can never silently
 * place a real order before it is intentionally enabled.
 */
class ZerodhaBroker implements BrokerAdapter
{
    public function __construct(
        protected TradingAccount $account,
        protected Broker $broker,
        protected TradingConfigService $config,
    ) {}

    public function slug(): string
    {
        return 'zerodha';
    }

    public function placeOrder(array $payload): array
    {
        throw new RuntimeException('ZerodhaBroker is not wired for live execution yet.');
    }

    public function cancelOrder(string $orderRef): array
    {
        throw new RuntimeException('ZerodhaBroker is not wired for live execution yet.');
    }

    public function getPositions(): array
    {
        throw new RuntimeException('ZerodhaBroker is not wired yet.');
    }

    public function getBalances(): array
    {
        throw new RuntimeException('ZerodhaBroker is not wired yet.');
    }

    public function getOrderStatus(string $orderRef): array
    {
        throw new RuntimeException('ZerodhaBroker is not wired yet.');
    }

    public function isApiAvailable(): bool
    {
        return false;
    }
}
