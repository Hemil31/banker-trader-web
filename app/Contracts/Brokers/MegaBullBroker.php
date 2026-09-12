<?php

namespace App\Contracts\Brokers;

use App\Models\Broker;
use App\Models\TradingAccount;
use App\Services\TradingConfigService;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * MegaBull free paper-trading API (India) — https://api.megabull.in.
 *
 * Each user supplies their own personal `api-key` (generated from their
 * MegaBull profile; no app-level secret). Unlike Kotak/Upstox this account
 * still trades virtual money, so it is registered as a `paper`-flagged
 * broker — see BrokerOAuthService::connectMegaBull() for the connect flow.
 *
 * Orders are addressed by MegaBull's numeric `instrumentToken` (the same
 * numbering as Zerodha's instrument master), resolved from our stock symbol
 * via the instrument CSV MegaBull publishes at `/api/marketwatch/instruments`.
 *
 * OrderRequest shape (confirmed from the public spec at
 * https://megabull.in/api/megabull-openapi.json — the api.megabull.in copy
 * requires a logged-in browser session, not the api-key, to fetch):
 *   instrumentToken (string), qty (int), price (number, LIMIT only),
 *   triggerPrice (number, SL only), type (BUY|SELL), orderType (LIMIT|MKT|SL),
 *   duration (MIS|CNC — this is actually the product type: intraday vs
 *   delivery, not order validity, despite the name).
 */
class MegaBullBroker implements BrokerAdapter
{
    protected const API_BASE_CONFIG_KEY = 'brokers.megabull.api_base';

    public function __construct(
        protected TradingAccount $account,
        protected Broker $broker,
        protected TradingConfigService $config,
    ) {}

    public function slug(): string
    {
        return 'megabull';
    }

    public function placeOrder(array $payload): array
    {
        $side = strtoupper((string) $payload['side']);
        $quantity = (int) round((float) $payload['quantity']);
        $symbol = strtoupper((string) ($payload['stock'] ?? ''));
        $price = (float) ($payload['price'] ?? 0);

        if (! in_array($side, ['BUY', 'SELL'], true) || $quantity <= 0 || $symbol === '') {
            throw new RuntimeException('MegaBull order requires a side (BUY/SELL), a symbol and a positive quantity.');
        }

        $instrumentToken = $this->resolveInstrumentToken($symbol);

        $body = [
            'instrumentToken' => $instrumentToken,
            'qty' => $quantity,
        ] + $this->buySellFields($side, $price, $payload);

        $response = $this->api()->post('/api/order/buysell', $body);
        $data = $this->dataOrFail($response, 'placeOrder');

        $orderId = $data['id'] ?? null;

        if ($orderId === null) {
            throw new RuntimeException('MegaBull did not return an order id: '.$response->body());
        }

        $status = $this->normalizeOrderStatus((string) ($data['status'] ?? 'open'));

        return [
            'order_ref' => (string) $orderId,
            'status' => $status,
            'filled_qty' => $status === 'filled' ? (float) ($data['qty'] ?? $quantity) : 0,
            'avg_price' => (float) ($data['price'] ?? $price),
            'slippage' => 0,
            'raw' => $data,
        ];
    }

    public function cancelOrder(string $orderRef): array
    {
        try {
            $response = $this->api()->put('/api/order/bulk/cancel', [(int) $orderRef]);

            if ($response->failed()) {
                return ['ok' => false, 'message' => "Cancel failed: {$response->body()}"];
            }

            return ['ok' => true, 'message' => "Order {$orderRef} cancellation submitted."];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => "Cancel failed: {$e->getMessage()}"];
        }
    }

    public function getPositions(): array
    {
        // We always trade CNC (delivery — see buySellFields()), so filled
        // orders settle into /api/holding/my, not /api/position/my (that one
        // is for MIS/intraday exposure, confirmed empty live for a CNC
        // fill). Check both so this stays correct if that ever changes.
        $rows = [
            ...$this->listOrFail($this->api()->get('/api/position/my'), 'getPositions'),
            ...$this->listOrFail($this->api()->get('/api/holding/my'), 'getHoldings'),
        ];

        $positions = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $quantity = (float) ($row['qty'] ?? 0);
            if ($quantity === 0.0) {
                continue;
            }

            $positions[] = [
                'symbol' => (string) ($row['instrumentName'] ?? ''),
                'quantity' => $quantity,
                'avg_price' => (float) ($row['priceAvg'] ?? 0),
                'pnl' => (float) ($row['pl'] ?? 0),
            ];
        }

        return $positions;
    }

    public function getBalances(): array
    {
        $response = $this->api()->get('/api/user/my');
        $data = $this->dataOrFail($response, 'getBalances');

        return [
            'available_cash' => (float) ($data['virtualMoneyLeft'] ?? 0),
            'invested' => (float) ($data['virtualMoneyBlocked'] ?? 0),
        ];
    }

    public function getOrderStatus(string $orderRef): array
    {
        $response = $this->api()->get('/api/order/my');
        $data = $this->dataOrFail($response, 'getOrderStatus');

        $all = array_merge(
            is_array($data['open'] ?? null) ? $data['open'] : [],
            is_array($data['executed'] ?? null) ? $data['executed'] : [],
        );

        foreach ($all as $order) {
            $id = is_array($order) ? ($order['id'] ?? null) : null;
            if ((string) $id === $orderRef) {
                $status = $this->normalizeOrderStatus((string) ($order['status'] ?? ''));

                return [
                    'status' => $status,
                    'filled_qty' => $status === 'filled' ? (float) ($order['qty'] ?? 0) : 0,
                    'avg_price' => (float) ($order['price'] ?? 0),
                ];
            }
        }

        throw new RuntimeException("Order {$orderRef} not found in the MegaBull order book.");
    }

    public function isApiAvailable(): bool
    {
        try {
            return $this->api()->get('/api/user/my')->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Side, order type, price/trigger and product fields for OrderRequest.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function buySellFields(string $side, float $price, array $payload): array
    {
        $orderType = match (strtoupper((string) ($payload['type'] ?? 'market'))) {
            'LIMIT' => 'LIMIT',
            'SL', 'STOPLOSS', 'STOP LOSS' => 'SL',
            default => 'MKT',
        };

        $fields = [
            'type' => $side,
            'orderType' => $orderType,
            // MegaBull's "duration" is actually the product type (MIS
            // intraday vs CNC delivery), not order validity. Our strategy
            // holds positions across sessions, so always trade delivery.
            'duration' => 'CNC',
            // Confirmed live: MegaBull requires `price` on every order, not
            // just LIMIT ones (the docs only say LIMIT needs it) — omitting
            // it fails with "Price cannot be Blank" even for MKT orders.
            'price' => $price,
        ];

        if ($orderType === 'SL') {
            $fields['triggerPrice'] = (float) ($payload['trigger_price'] ?? $price);
        }

        return $fields;
    }

    protected function resolveInstrumentToken(string $symbol): string
    {
        $map = Cache::remember(
            "megabull:instrument-tokens:{$this->account->id}",
            now()->addHours(12),
            fn () => $this->fetchInstrumentTokens(),
        );

        $token = $map[$symbol] ?? null;

        if ($token === null) {
            throw new RuntimeException("MegaBull has no instrument token for symbol {$symbol}.");
        }

        return $token;
    }

    /**
     * @return array<string, string>
     */
    protected function fetchInstrumentTokens(): array
    {
        $discovery = $this->dataOrFail($this->api()->get('/api/marketwatch/instruments'), 'instruments');

        $downloadUrl = (string) ($discovery['downloadUrl'] ?? '');

        if ($downloadUrl === '') {
            throw new RuntimeException('MegaBull instruments endpoint did not return a download URL.');
        }

        $csv = Http::timeout(20)->get($downloadUrl)->body();

        $map = [];
        $lines = preg_split('/\r\n|\r|\n/', trim($csv)) ?: [];

        foreach (array_slice($lines, 1) as $line) {
            if ($line === '') {
                continue;
            }

            $columns = str_getcsv($line, ',', '"', '\\');
            $symbol = strtoupper(trim((string) ($columns[0] ?? '')));
            $token = trim((string) ($columns[2] ?? ''));

            if ($symbol !== '' && $token !== '') {
                $map[$symbol] = $token;
            }
        }

        return $map;
    }

    protected function api(): PendingRequest
    {
        $apiKey = (string) $this->account->getCredential('api_key');

        if ($apiKey === '') {
            throw new RuntimeException('MegaBull API key is missing. Connect the broker first.');
        }

        $baseUrl = (string) config(self::API_BASE_CONFIG_KEY, 'https://api.megabull.in');

        return Http::baseUrl($baseUrl)
            ->withHeaders(['api-key' => $apiKey])
            ->acceptJson()
            ->timeout(15);
    }

    /**
     * @return array<string, mixed>
     */
    protected function dataOrFail(Response $response, string $operation): array
    {
        $body = $response->json();

        if ($response->failed() || ! is_array($body)) {
            $message = is_array($body) ? (string) ($body['message'][0] ?? $body['error'] ?? 'unknown error') : $response->body();

            throw new RuntimeException("MegaBull {$operation} failed: {$message}");
        }

        return $body;
    }

    /**
     * @return array<int, mixed>
     */
    protected function listOrFail(Response $response, string $operation): array
    {
        $body = $response->json();

        if ($response->failed()) {
            $message = is_array($body) ? (string) ($body['message'][0] ?? $body['error'] ?? 'unknown error') : $response->body();

            throw new RuntimeException("MegaBull {$operation} failed: {$message}");
        }

        return is_array($body) ? $body : [];
    }

    /**
     * Confirmed live against the real API: PENDING (open), CANCELLED,
     * COMPLETE (guessed name for a filled order — MegaBull's paper engine
     * fills market orders immediately, so this hasn't been observed directly
     * yet, but PENDING/CANCELLED round-tripped exactly as below).
     */
    protected function normalizeOrderStatus(string $status): string
    {
        return match (strtoupper($status)) {
            'COMPLETE', 'FILLED', 'EXECUTED' => 'filled',
            'CANCELLED', 'CANCELED' => 'cancelled',
            'REJECTED' => 'rejected',
            'PARTIAL', 'PARTIALLY_FILLED' => 'partial',
            'PENDING' => 'acknowledged',
            default => 'acknowledged',
        };
    }
}
