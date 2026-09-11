<?php

namespace App\Contracts\Brokers;

use App\Models\Broker;
use App\Models\TradingAccount;
use App\Services\TradingConfigService;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Live Angel One (SmartAPI) adapter.
 *
 * Angel authenticates through a publisher-login redirect: the browser returns
 * `auth_token` (Bearer) + `feed_token`, which are stored encrypted in
 * trading_accounts.credentials. Every call sends the app-level API key via the
 * X-PrivateKey header. The session token expires at midnight; re-connection is
 * required afterwards.
 *
 * Execution result shape follows the BrokerAdapter contract:
 *   status: acknowledged | filled | partial | rejected | cancelled
 */
class AngelBroker implements BrokerAdapter
{
    /** @var array<string, string> NSE-CM symboltoken map for the watchlist. */
    protected static array $symbolTokenMap = [
        'RELIANCE' => '2885',
        'TCS' => '11536',
        'INFY' => '1594',
        'HDFCBANK' => '1333',
        'ICICIBANK' => '4963',
        'SBIN' => '3045',
        'MARUTI' => '2833',
        'ITC' => '1660',
    ];

    /** @var array<string, string> */
    protected static array $tokenCache = [];

    protected const ENDPOINTS = [
        'order_place' => '/rest/secure/angelbroking/order/v1/placeOrder',
        'order_cancel' => '/rest/secure/angelbroking/order/v1/cancelOrder',
        'get_order_book' => '/rest/secure/angelbroking/order/v1/getOrderBook',
        'get_position' => '/rest/secure/angelbroking/order/v1/getPosition',
        'get_rms' => '/rest/secure/angelbroking/user/v1/getRMS',
        'get_profile' => '/rest/secure/angelbroking/user/v1/getProfile',
    ];

    public function __construct(
        protected TradingAccount $account,
        protected Broker $broker,
        protected TradingConfigService $config,
    ) {}

    public function slug(): string
    {
        return 'angel';
    }

    /**
     * Resolve an NSE symbol (e.g. "RELIANCE") to its Angel symboltoken.
     */
    public function resolveSymbolToken(string $symbol): string
    {
        $symbol = strtoupper(trim($symbol));

        if (isset(self::$tokenCache[$symbol])) {
            return self::$tokenCache[$symbol];
        }

        $token = self::$symbolTokenMap[$symbol] ?? null;

        if ($token === null) {
            throw new RuntimeException(
                "No Angel symboltoken mapped for: {$symbol}. Add it to the NSE-CM token map."
            );
        }

        return self::$tokenCache[$symbol] = $token;
    }

    public function placeOrder(array $payload): array
    {
        $side = strtoupper((string) $payload['side']);
        $quantity = (int) round((float) $payload['quantity']);
        $symbol = strtoupper((string) ($payload['stock'] ?? ''));
        $type = strtoupper((string) ($payload['type'] ?? 'market'));

        if (! in_array($side, ['BUY', 'SELL'], true) || $quantity <= 0 || $symbol === '') {
            throw new RuntimeException('Angel order requires a side (BUY/SELL), a symbol and a positive quantity.');
        }

        $isLimit = $type === 'LIMIT';

        $response = $this->api()->post($this->endpoint('order_place'), [
            'variety' => 'NORMAL',
            'tradingsymbol' => $symbol.'-EQ',
            'symboltoken' => $this->resolveSymbolToken($symbol),
            'exchange' => 'NSE',
            'transactiontype' => $side,
            'ordertype' => $isLimit ? 'LIMIT' : 'MARKET',
            'producttype' => 'INTRADAY',
            'duration' => 'DAY',
            'price' => $isLimit ? (string) round((float) ($payload['price'] ?? 0), 2) : '0',
            'quantity' => $quantity,
            'triggerprice' => '0',
            'squareoff' => '0',
            'stoploss' => '0',
            'disclosedquantity' => '0',
        ]);

        $data = $this->dataOrFail($response, 'placeOrder');

        $orderId = $data['orderid'] ?? null;

        if ($orderId === null || $orderId === '') {
            throw new RuntimeException('Angel did not return an order id.');
        }

        return [
            'order_ref' => (string) $orderId,
            'status' => 'acknowledged',
            'filled_qty' => 0,
            'avg_price' => 0,
            'slippage' => 0,
            'raw' => $response->json(),
        ];
    }

    public function cancelOrder(string $orderRef): array
    {
        try {
            $response = $this->api()->post($this->endpoint('order_cancel'), [
                'variety' => 'NORMAL',
                'orderid' => $orderRef,
            ]);

            $this->dataOrFail($response, 'cancelOrder');

            return ['ok' => true, 'message' => "Order {$orderRef} cancellation submitted."];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => "Cancel failed: {$e->getMessage()}"];
        }
    }

    public function getPositions(): array
    {
        $response = $this->api()->get($this->endpoint('get_position'));

        $data = $this->dataOrFail($response, 'getPosition');

        $records = $this->positionRecords($data);

        return array_map(static function (array $position): array {
            return [
                'symbol' => (string) ($position['tradingsymbol'] ?? ''),
                'quantity' => (float) ($position['quantity'] ?? 0),
                'avg_price' => (float) ($position['averageprice'] ?? 0),
                'pnl' => (float) ($position['pnl'] ?? 0),
            ];
        }, $records);
    }

    public function getBalances(): array
    {
        $response = $this->api()->get($this->endpoint('get_rms'));

        $data = $this->dataOrFail($response, 'getRMS');

        return [
            'available_cash' => (float) ($data['availablecash'] ?? 0),
            'invested' => (float) ($data['marginused'] ?? 0),
        ];
    }

    public function getOrderStatus(string $orderRef): array
    {
        $response = $this->api()->get($this->endpoint('get_order_book'));

        $data = $this->dataOrFail($response, 'getOrderBook');

        foreach ($data as $order) {
            if (is_array($order) && ($order['orderid'] ?? null) === $orderRef) {
                return [
                    'status' => $this->normalizeOrderStatus((string) ($order['status'] ?? '')),
                    'filled_qty' => (float) ($order['filledqty'] ?? 0),
                    'avg_price' => (float) ($order['averageprice'] ?? 0),
                    'message' => (string) ($order['statusmessage'] ?? ''),
                ];
            }
        }

        throw new RuntimeException("Order {$orderRef} not found in the Angel order book.");
    }

    public function isApiAvailable(): bool
    {
        try {
            $response = $this->api()->get($this->endpoint('get_profile'));

            $body = $response->json();

            return $response->successful() && is_array($body) && ($body['status'] ?? false) === true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Shared SmartAPI HTTP client bound to this user's access token.
     */
    protected function api(): PendingRequest
    {
        $token = $this->account->getAccessToken();
        $apiKey = (string) config('brokers.angel.api_key');

        if (empty($token)) {
            throw new RuntimeException('Angel auth token is missing. Connect the broker first.');
        }

        if ($apiKey === '') {
            throw new RuntimeException("Broker 'angel' is not configured. Set ANGEL_API_KEY first.");
        }

        return Http::baseUrl((string) config('brokers.angel.api_base'))
            ->withHeaders([
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                'X-PrivateKey' => $apiKey,
                'X-UserType' => 'USER',
                'X-SourceID' => 'WEB',
                'X-ClientLocalIP' => '192.168.168.168',
                'X-ClientPublicIP' => '106.193.147.98',
                'X-MACAddress' => 'fe80::216e:6507:4b90:3719',
                'Authorization' => 'Bearer '.$token,
            ])
            ->asJson()
            ->acceptJson()
            ->timeout(15);
    }

    protected function endpoint(string $key): string
    {
        return (string) (self::ENDPOINTS[$key] ?? throw new RuntimeException("Unknown Angel endpoint: {$key}"));
    }

    /**
     * Decode the Angel response envelope and strip the inner `data` payload.
     *
     * @return array<string, mixed>
     */
    protected function dataOrFail(Response $response, string $operation): array
    {
        $body = $response->json();

        if ($response->failed() || ! is_array($body) || ($body['status'] ?? false) !== true) {
            $message = is_array($body) ? (string) ($body['message'] ?? $body['errorcode'] ?? $body['error'] ?? 'unknown error') : $response->body();

            throw new RuntimeException("Angel {$operation} failed: {$message}");
        }

        $data = $body['data'] ?? [];

        if (is_array($data)) {
            return $data;
        }

        throw new RuntimeException("Angel {$operation} returned an unexpected response.");
    }

    /**
     * getPosition may nest day/night records under `net` and `day`.
     *
     * @param  array<string, mixed>  $data
     * @return array<int, array<string, mixed>>
     */
    protected function positionRecords(array $data): array
    {
        $net = $data['net'] ?? null;
        $net = is_array($net) && array_is_list($net) ? $net : $data;

        if (! array_is_list($net)) {
            return [];
        }

        return array_filter($net, static fn (mixed $row): bool => is_array($row));
    }

    protected function normalizeOrderStatus(string $status): string
    {
        return match (strtolower($status)) {
            'complete', 'filled', 'confirmed', 'triggered' => 'filled',
            'cancelled', 'canceled' => 'cancelled',
            'rejected' => 'rejected',
            default => 'acknowledged',
        };
    }
}
