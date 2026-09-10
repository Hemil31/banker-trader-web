<?php

namespace App\Contracts\Brokers;

use App\Models\Broker;
use App\Models\TradingAccount;
use App\Services\TradingConfigService;
use GuzzleHttp\Client;
use RuntimeException;
use Upstox\Client\Api\InstrumentsApi;
use Upstox\Client\Api\OrderApi;
use Upstox\Client\Api\OrderApiV3;
use Upstox\Client\Api\PortfolioApi;
use Upstox\Client\Api\UserApi;
use Upstox\Client\Api\WebsocketApi;
use Upstox\Client\Configuration;
use Upstox\Client\Model\PlaceOrderV3Request;
use Upstox\Client\Model\PositionData;

/**
 * Live Upstox adapter.
 *
 * Uses the user's per-account access token (stored encrypted in
 * trading_accounts.credentials) with the Upstox PHP SDK. Sandbox vs production
 * is controlled by the UPSTOX_SANDBOX env flag.
 *
 * Execution result shape follows the BrokerAdapter contract:
 *   status: acknowledged | filled | partial | rejected | cancelled
 */
class UpstoxBroker implements BrokerAdapter
{
    /** @var array<string, string> */
    protected static array $instrumentCache = [];

    public function __construct(
        protected TradingAccount $account,
        protected Broker $broker,
        protected TradingConfigService $config,
    ) {}

    public function slug(): string
    {
        return 'upstox';
    }

    /**
     * SDK configuration bound to this user's Upstox access token.
     */
    protected function sdkConfig(): Configuration
    {
        $token = $this->account->getAccessToken();

        if (empty($token)) {
            throw new RuntimeException('Upstox access token is missing. Connect the broker first.');
        }

        return Configuration::getDefaultConfiguration((bool) config('brokers.upstox.sandbox', true))
            ->setAccessToken($token);
    }

    protected function orderApi(): OrderApiV3
    {
        return new OrderApiV3(new Client, $this->sdkConfig());
    }

    protected function orderApiLegacy(): OrderApi
    {
        return new OrderApi(new Client, $this->sdkConfig());
    }

    protected function portfolioApi(): PortfolioApi
    {
        return new PortfolioApi(new Client, $this->sdkConfig());
    }

    protected function userApi(): UserApi
    {
        return new UserApi(new Client, $this->sdkConfig());
    }

    protected function instrumentsApi(): InstrumentsApi
    {
        return new InstrumentsApi(new Client, $this->sdkConfig());
    }

    protected function websocketApi(): WebsocketApi
    {
        return new WebsocketApi(new Client, $this->sdkConfig());
    }

    /**
     * Resolve an NSE trading symbol (e.g. "RELIANCE") to an Upstox
     * instrument key (e.g. "NSE_EQ|INE669E01016") via searchInstrument.
     */
    public function resolveInstrumentKey(string $symbol): string
    {
        $symbol = strtoupper(trim($symbol));

        if (isset(self::$instrumentCache[$symbol])) {
            return self::$instrumentCache[$symbol];
        }

        $response = $this->instrumentsApi()->searchInstrument(
            query: $symbol,
            exchanges: 'NSE',
            segments: 'EQ',
            instrument_types: 'equity',
        );

        $metaData = $response->getData();
        $records = $metaData?->getPage()?->getRecords() ?? [];

        foreach ($records as $record) {
            $key = $record->getInstrumentKey();
            if ($key && str_contains($key, 'NSE_EQ|')) {
                return self::$instrumentCache[$symbol] = $key;
            }
        }

        throw new RuntimeException("Could not resolve Upstox instrument for symbol: {$symbol}");
    }

    public function placeOrder(array $payload): array
    {
        $side = strtoupper((string) $payload['side']);
        $quantity = (int) round((float) $payload['quantity']);
        $symbol = (string) ($payload['stock'] ?? '');
        $type = strtoupper((string) ($payload['type'] ?? 'market'));

        if ($quantity <= 0 || $symbol === '') {
            throw new RuntimeException('Upstox order requires a symbol and a positive quantity.');
        }

        $body = new PlaceOrderV3Request;
        $body->setQuantity($quantity);
        $body->setProduct('D');
        $body->setValidity('DAY');
        $body->setPrice(0);
        $body->setOrderType($type === 'LIMIT' ? 'LIMIT' : 'MARKET');
        $body->setInstrumentToken($this->resolveInstrumentKey($symbol));
        $body->setTransactionType($side);
        $body->setDisclosedQuantity(0);
        $body->setTriggerPrice(0);
        $body->setIsAmo(false);
        $body->setSlice(true);

        $origin = (string) ($payload['origin'] ?? 'web');
        $algoName = $this->account->algo_name;

        $response = $this->orderApi()->placeOrder($body, $origin, $algoName);

        $orderIds = $response->getData()->getOrderIds();
        $orderRef = $orderIds === [] ? null : $orderIds[0];

        if ($orderRef === null) {
            throw new RuntimeException('Upstox did not return an order id.');
        }

        // Poll briefly for an immediate fill so the normalized result reflects
        // reality when possible (market orders often fill in milliseconds).
        return $this->pollOrderResult($orderRef, 10, 300, compact('response'));
    }

    public function cancelOrder(string $orderRef): array
    {
        try {
            $this->orderApi()->cancelOrder($orderRef, 'web', $this->account->algo_name);

            return ['ok' => true, 'message' => "Order {$orderRef} cancellation submitted."];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => "Cancel failed: {$e->getMessage()}"];
        }
    }

    public function getPositions(): array
    {
        $response = $this->portfolioApi()->getPositions('2.0');

        return array_map(function (PositionData $position): array {
            return [
                'symbol' => (string) $position->getTradingSymbol() ?: (string) $position->getInstrumentToken(),
                'quantity' => (float) $position->getQuantity(),
                'avg_price' => (float) $position->getAveragePrice(),
                'pnl' => (float) $position->getUnrealised(),
            ];
        }, $response->getData());
    }

    public function getBalances(): array
    {
        $response = $this->userApi()->getUserFundMargin('2.0');
        $data = $response->getData();

        return [
            'available_cash' => (float) $data->getAvailableMargin(),
            'invested' => (float) $data->getUsedMargin(),
        ];
    }

    public function getOrderStatus(string $orderRef): array
    {
        $response = $this->orderApiLegacy()->getOrderStatus($orderRef);
        $order = $response->getData();

        return [
            'status' => $this->normalizeOrderStatus((string) $order->getStatus()),
            'filled_qty' => (float) $order->getFilledQuantity(),
            'avg_price' => (float) $order->getAveragePrice(),
            'message' => (string) $order->getStatusMessage(),
        ];
    }

    public function isApiAvailable(): bool
    {
        try {
            // Lightweight authenticated call to confirm reachability + token validity.
            $this->userApi()->getProfile('2.0');

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Authorized WebSocket URL for live market data (V3 feed).
     */
    public function marketDataFeedUri(): string
    {
        $uri = $this->websocketApi()
            ->getMarketDataFeedAuthorizeV3()
            ->getData()
            ->getAuthorizedRedirectUri();

        if (! $uri) {
            throw new RuntimeException('Upstox did not return a market data feed URL.');
        }

        return (string) $uri;
    }

    /**
     * Authorized WebSocket URL for live portfolio updates (orders/holdings/positions).
     */
    public function portfolioFeedUri(): string
    {
        $uri = $this->websocketApi()
            ->getPortfolioStreamFeedAuthorize('2.0', true, true, true)
            ->getData()
            ->getAuthorizedRedirectUri();

        if (! $uri) {
            throw new RuntimeException('Upstox did not return a portfolio feed URL.');
        }

        return (string) $uri;
    }

    /**
     * Poll the order status a short time for an immediate fill.
     *
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    protected function pollOrderResult(string $orderRef, int $attempts, int $delayMs, array $raw): array
    {
        for ($i = 0; $i < $attempts; $i++) {
            if ($i > 0) {
                usleep($delayMs * 1000);
            }

            try {
                $status = $this->getOrderStatus($orderRef);
            } catch (\Throwable $e) {
                return [
                    'order_ref' => $orderRef,
                    'status' => 'acknowledged',
                    'filled_qty' => 0,
                    'avg_price' => 0,
                    'slippage' => 0,
                    'raw' => $raw + ['poll_error' => $e->getMessage()],
                ];
            }

            if (in_array($status['status'], ['filled', 'partial', 'rejected', 'cancelled'], true)) {
                return [
                    'order_ref' => $orderRef,
                    'status' => $status['status'],
                    'filled_qty' => (float) $status['filled_qty'],
                    'avg_price' => (float) $status['avg_price'],
                    'slippage' => 0,
                    'raw' => $raw + ['order_status' => $status],
                ];
            }

            usleep($delayMs * 1000);
        }

        return [
            'order_ref' => $orderRef,
            'status' => 'acknowledged',
            'filled_qty' => 0,
            'avg_price' => 0,
            'slippage' => 0,
            'raw' => $raw,
        ];
    }

    protected function normalizeOrderStatus(?string $status): string
    {
        return match (strtolower((string) $status)) {
            'complete', 'filled', 'confirmed', 'triggered' => 'filled',
            'cancelled', 'canceled' => 'cancelled',
            'rejected' => 'rejected',
            default => 'acknowledged',
        };
    }
}
