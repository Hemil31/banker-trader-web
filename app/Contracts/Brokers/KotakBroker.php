<?php

namespace App\Contracts\Brokers;

use App\Models\Broker;
use App\Models\TradingAccount;
use App\Services\TradingConfigService;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Live Kotak Neo (Kotak Securities) adapter.
 *
 * Kotak authenticates server-side with a TOTP login: the user supplies their
 * mobile number, client UCC and the current TOTP, then the login MPIN. The
 * resulting session (edit token + sid, plus the data-center base URL) is stored
 * encrypted in trading_accounts.credentials under `access_token` / `sid`.
 *
 * The app-level consumer key is sent as the `Authorization` header on every
 * request. Order bodies use Kotak's form-encoded `jData` convention.
 *
 * Execution result shape follows the BrokerAdapter contract:
 *   status: acknowledged | filled | partial | rejected | cancelled
 */
class KotakBroker implements BrokerAdapter
{
    protected const ORDER_SOURCE = 'NEOTRADEAPI';

    protected const ENDPOINTS = [
        'order_place' => '/quick/order/rule/ms/place',
        'order_cancel' => '/quick/order/cancel',
        'get_order_book' => '/quick/user/orders',
        'get_positions' => '/quick/user/positions',
        'get_limits' => '/quick/user/limits',
    ];

    public function __construct(
        protected TradingAccount $account,
        protected Broker $broker,
        protected TradingConfigService $config,
    ) {}

    public function slug(): string
    {
        return 'kotak';
    }

    public function placeOrder(array $payload): array
    {
        $side = strtoupper((string) $payload['side']);
        $quantity = (int) round((float) $payload['quantity']);
        $symbol = strtoupper((string) ($payload['stock'] ?? ''));
        $type = strtoupper((string) ($payload['type'] ?? 'market'));

        if (! in_array($side, ['BUY', 'SELL'], true) || $quantity <= 0 || $symbol === '') {
            throw new RuntimeException('Kotak order requires a side (BUY/SELL), a symbol and a positive quantity.');
        }

        $orderType = $this->normalizeOrderType($type);
        $isPriceRequired = in_array($orderType, ['L', 'SL'], true);
        $price = $isPriceRequired ? number_format((float) ($payload['price'] ?? 0), 2, '.', '') : '0';
        $trigger = $orderType === 'SL-M' || $orderType === 'SL'
            ? number_format((float) Arr::get($payload, 'trigger_price', 0), 2, '.', '')
            : '0';

        $response = $this->api()->asForm()->post($this->endpoint('order_place'), [
            'jData' => json_encode([
                'am' => 'NO',
                'dq' => null,
                'es' => 'nse_cm',
                'mp' => '0',
                'pc' => (string) Arr::get($payload, 'product', 'MIS'),
                'pr' => $price,
                'pt' => $orderType,
                'qt' => $quantity,
                'rt' => 'DAY',
                'tp' => $trigger,
                'ts' => $symbol.'-EQ',
                'tt' => $side === 'BUY' ? 'B' : 'S',
                'ig' => null,
                'os' => self::ORDER_SOURCE,
            ], JSON_THROW_ON_ERROR),
        ]);

        $body = $response->json();
        $orderId = is_array($body) ? ($body['nOrdNo'] ?? null) : null;

        if ($response->failed() || ! is_array($body) || strtolower((string) ($body['stat'] ?? '')) !== 'ok') {
            $message = is_array($body) ? (string) ($body['errMsg'] ?? $body['stat'] ?? 'unknown error') : $response->body();

            throw new RuntimeException("Kotak placeOrder failed: {$message}");
        }

        if ($orderId === null || $orderId === '') {
            throw new RuntimeException('Kotak did not return an order number.');
        }

        return [
            'order_ref' => (string) $orderId,
            'status' => 'acknowledged',
            'filled_qty' => 0,
            'avg_price' => 0,
            'slippage' => 0,
            'raw' => $body,
        ];
    }

    public function cancelOrder(string $orderRef): array
    {
        try {
            $response = $this->api()->asForm()->post($this->endpoint('order_cancel'), [
                'jData' => json_encode([
                    'on' => $orderRef,
                    'am' => 'NO',
                ], JSON_THROW_ON_ERROR),
            ]);

            $body = $response->json();

            if ($response->failed() || ! is_array($body) || strtolower((string) ($body['stat'] ?? '')) !== 'ok') {
                $message = is_array($body) ? (string) ($body['errMsg'] ?? $body['stat'] ?? 'unknown error') : $response->body();

                throw new RuntimeException("Kotak cancel failed: {$message}");
            }

            return ['ok' => true, 'message' => "Order {$orderRef} cancellation submitted."];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => "Cancel failed: {$e->getMessage()}"];
        }
    }

    public function getPositions(): array
    {
        $response = $this->api()->get($this->endpoint('get_positions'));

        $data = $this->dataListOrFail($response, 'positions');

        $positions = [];

        foreach ($data as $row) {
            if (! is_array($row)) {
                continue;
            }

            $netQty = $this->netQuantity($row);

            if ($netQty === 0.0) {
                continue;
            }

            $positions[] = [
                'symbol' => (string) ($row['trdSym'] ?? $row['sym'] ?? ''),
                'quantity' => $netQty,
                'avg_price' => $this->averagePrice($row),
                'pnl' => 0.0,
            ];
        }

        return $positions;
    }

    public function getBalances(): array
    {
        $response = $this->api()->asForm()->post($this->endpoint('get_limits'), [
            'jData' => json_encode([
                'seg' => 'ALL',
                'exch' => 'ALL',
                'prod' => 'ALL',
            ], JSON_THROW_ON_ERROR),
        ]);

        $body = $response->json();

        if ($response->failed() || ! is_array($body)) {
            $message = is_array($body) ? (string) ($body['errMsg'] ?? $body['stat'] ?? 'unknown error') : $response->body();

            throw new RuntimeException("Kotak getBalances failed: {$message}");
        }

        return [
            'available_cash' => (float) ($body['Net'] ?? 0),
            'invested' => (float) ($body['MarginUsed'] ?? 0),
        ];
    }

    public function getOrderStatus(string $orderRef): array
    {
        $response = $this->api()->get($this->endpoint('get_order_book'));

        $data = $this->dataListOrFail($response, 'order book');

        foreach ($data as $order) {
            if (is_array($order) && ($order['nOrdNo'] ?? null) === $orderRef) {
                return [
                    'status' => $this->normalizeOrderStatus((string) ($order['ordSt'] ?? $order['stat'] ?? '')),
                    'filled_qty' => (float) ($order['fldQty'] ?? 0),
                    'avg_price' => (float) ($order['avgPrc'] ?? 0),
                    'message' => (string) ($order['rejRsn'] ?? ''),
                ];
            }
        }

        throw new RuntimeException("Order {$orderRef} not found in the Kotak order book.");
    }

    public function isApiAvailable(): bool
    {
        try {
            $response = $this->api()->get($this->endpoint('get_positions'));

            $body = $response->json();

            return $response->successful()
                && is_array($body)
                && ! isset($body['errMsg'])
                && array_key_exists('data', $body);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Shared Kotak HTTP client bound to the account's session (sid + token).
     *
     * The data-center base URL returned by MPIN validation is preferred; it
     * falls back to the configured production base URL.
     */
    protected function api(): PendingRequest
    {
        $consumerKey = (string) config('brokers.kotak.consumer_key');
        $token = $this->account->getAccessToken();
        $sid = (string) $this->account->getCredential('sid');

        if ($consumerKey === '') {
            throw new RuntimeException("Broker 'kotak' is not configured. Set KOTAK_CONSUMER_KEY first.");
        }

        if ($token === '' || $token === null) {
            throw new RuntimeException('Kotak session token is missing. Connect the broker first.');
        }

        if ($sid === '') {
            throw new RuntimeException('Kotak session id is missing. Connect the broker first.');
        }

        $baseUrl = (string) ($this->account->getCredential('base_url') ?: config('brokers.kotak.api_base'));

        return Http::baseUrl($baseUrl)
            ->withHeaders([
                'Accept' => 'application/json',
                'Authorization' => $consumerKey,
                'Sid' => $sid,
                'Auth' => $token,
            ])
            ->acceptJson()
            ->timeout(15);
    }

    protected function endpoint(string $key): string
    {
        return (string) (self::ENDPOINTS[$key] ?? throw new RuntimeException("Unknown Kotak endpoint: {$key}"));
    }

    /**
     * Decode the Kotak list envelope (`stat`/`data`) used by positions and the
     * order book.
     *
     * @return array<int, mixed>
     */
    protected function dataListOrFail(Response $response, string $operation): array
    {
        $body = $response->json();

        if ($response->failed() || ! is_array($body)) {
            $message = is_array($body) ? (string) ($body['errMsg'] ?? $body['stat'] ?? 'unknown error') : $response->body();

            throw new RuntimeException("Kotak {$operation} failed: {$message}");
        }

        $data = $body['data'] ?? null;

        if (is_array($data) && array_is_list($data)) {
            return $data;
        }

        throw new RuntimeException("Kotak {$operation} returned an unexpected response.");
    }

    /**
     * Net quantity per Kotak's position-calculations doc:
     * Net qty = (cfBuyQty + flBuyQty) - (cfSellQty + flSellQty); F&O rows are
     * divided by lot size.
     *
     * @param  array<string, mixed>  $row
     */
    protected function netQuantity(array $row): float
    {
        $buy = (float) ($row['cfBuyQty'] ?? 0) + (float) ($row['flBuyQty'] ?? 0);
        $sell = (float) ($row['cfSellQty'] ?? 0) + (float) ($row['flSellQty'] ?? 0);
        $net = $buy - $sell;

        $segment = (string) ($row['exSeg'] ?? '');
        $lotSize = in_array($segment, ['nse_fo', 'bse_fo', 'mcx_fo'], true)
            ? (float) ($row['lotSz'] ?? 1)
            : 1;

        return $lotSize > 0 ? round($net / $lotSize, 4) : 0.0;
    }

    /**
     * Average price per Kotak's position-calculations doc: buy average when net
     * long, sell average when net short (multiplier and prc/gen fractions
     * default to 1 when absent).
     *
     * @param  array<string, mixed>  $row
     */
    protected function averagePrice(array $row): float
    {
        $buyQty = (float) ($row['cfBuyQty'] ?? 0) + (float) ($row['flBuyQty'] ?? 0);
        $sellQty = (float) ($row['cfSellQty'] ?? 0) + (float) ($row['flSellQty'] ?? 0);

        if ($buyQty <= 0 && $sellQty <= 0) {
            return 0.0;
        }

        $factor = (float) ($row['multiplier'] ?? 1)
            * ((float) ($row['genNum'] ?? 1) / max((float) ($row['genDen'] ?? 1), 1))
            * ((float) ($row['prcNum'] ?? 1) / max((float) ($row['prcDen'] ?? 1), 1));

        if ($factor <= 0) {
            return 0.0;
        }

        $buyAmount = (float) ($row['cfBuyAmt'] ?? 0) + (float) ($row['buyAmt'] ?? 0);
        $sellAmount = (float) ($row['cfSellAmt'] ?? 0) + (float) ($row['sellAmt'] ?? 0);

        if ($buyQty >= $sellQty && $buyQty > 0) {
            return $buyAmount / ($buyQty * $factor);
        }

        if ($sellQty > 0) {
            return $sellAmount / ($sellQty * $factor);
        }

        return 0.0;
    }

    protected function normalizeOrderType(string $type): string
    {
        return match ($type) {
            'L', 'LIMIT' => 'L',
            'SL-M', 'SLM' => 'SL-M',
            'SL', 'STOPLOSS', 'STOP LOSS' => 'SL',
            default => 'MKT',
        };
    }

    protected function normalizeOrderStatus(string $status): string
    {
        return match (strtolower($status)) {
            'complete', 'filled', 'confirmed', 'triggered' => 'filled',
            'cancelled', 'canceled', 'cancel' => 'cancelled',
            'rejected' => 'rejected',
            'partially_filled', 'partial', 'partially filled' => 'partial',
            default => 'acknowledged',
        };
    }
}
