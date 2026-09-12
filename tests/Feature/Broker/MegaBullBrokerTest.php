<?php

namespace Tests\Feature\Broker;

use App\Contracts\Brokers\MegaBullBroker;
use App\Models\Broker;
use App\Models\TradingAccount;
use App\Models\User;
use App\Services\BrokerManager;
use App\Services\TradingConfigService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Laravel\Passport\Passport;
use Tests\TestCase;

class MegaBullBrokerTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private TradingAccount $account;

    private Broker $megabull;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);

        $this->user = User::factory()->create();
        $this->account = TradingAccount::factory()->for($this->user)->connectedMegaBull()->create();
        $this->megabull = Broker::where('slug', 'megabull')->firstOrFail();

        config(['brokers.megabull.api_base' => 'https://api.megabull.in']);
    }

    public function test_broker_manager_resolves_megabull_adapter_for_account(): void
    {
        $adapter = app(BrokerManager::class)->activeAdapter($this->account);

        $this->assertSame('megabull', $adapter->slug());
        $this->assertInstanceOf(MegaBullBroker::class, $adapter);
    }

    public function test_place_order_resolves_instrument_token_and_posts_order(): void
    {
        Http::fake([
            'https://api.megabull.in/api/marketwatch/instruments' => Http::response([
                'downloadUrl' => 'https://megabull-open.s3.us-east-1.amazonaws.com/Instrument-default.csv',
            ], 200),
            'https://megabull-open.s3.us-east-1.amazonaws.com/*' => Http::response(
                "\"tradingSymbol\",\"instrumentName\",\"instrumentToken\"\n\"RELIANCE\",\"RELIANCE\",\"738561\"\n",
                200,
            ),
            'https://api.megabull.in/api/order/buysell' => Http::response([
                'id' => 1001,
                'status' => 'OPEN',
                'qty' => 1,
                'price' => 2500,
                'type' => 'BUY',
                'orderType' => 'MKT',
                'duration' => 'CNC',
            ], 200),
        ]);

        $adapter = app(BrokerManager::class)->activeAdapter($this->account);

        $result = $adapter->placeOrder([
            'side' => 'buy',
            'quantity' => 1,
            'stock' => 'RELIANCE',
            'price' => 2500,
        ]);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.megabull.in/api/order/buysell'
                && $request->hasHeader('api-key', 'fake-megabull-api-key')
                && $request['instrumentToken'] === '738561'
                && $request['qty'] === 1
                && $request['type'] === 'BUY'
                && $request['orderType'] === 'MKT'
                && $request['duration'] === 'CNC';
        });

        $this->assertSame('1001', $result['order_ref']);
        $this->assertSame('acknowledged', $result['status']);
    }

    public function test_place_order_limit_includes_price_and_order_type(): void
    {
        Http::fake([
            'https://api.megabull.in/api/marketwatch/instruments' => Http::response([
                'downloadUrl' => 'https://megabull-open.s3.us-east-1.amazonaws.com/Instrument-default.csv',
            ], 200),
            'https://megabull-open.s3.us-east-1.amazonaws.com/*' => Http::response(
                "\"tradingSymbol\",\"instrumentName\",\"instrumentToken\"\n\"RELIANCE\",\"RELIANCE\",\"738561\"\n",
                200,
            ),
            'https://api.megabull.in/api/order/buysell' => Http::response([
                'id' => 1002,
                'status' => 'OPEN',
                'qty' => 2,
                'price' => 2450.5,
            ], 200),
        ]);

        $adapter = app(BrokerManager::class)->activeAdapter($this->account);

        $adapter->placeOrder([
            'side' => 'sell',
            'quantity' => 2,
            'stock' => 'RELIANCE',
            'type' => 'limit',
            'price' => 2450.5,
        ]);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.megabull.in/api/order/buysell'
                && $request['type'] === 'SELL'
                && $request['orderType'] === 'LIMIT'
                && $request['price'] === 2450.5
                && ! array_key_exists('triggerPrice', $request->data());
        });
    }

    public function test_place_order_requires_connected_account(): void
    {
        $account = TradingAccount::factory()->for($this->user)->create();

        $adapter = new MegaBullBroker($account, $this->megabull, app(TradingConfigService::class));

        $this->expectExceptionMessage('MegaBull API key is missing');

        $adapter->placeOrder(['side' => 'buy', 'quantity' => 1, 'stock' => 'RELIANCE', 'price' => 100]);
    }

    public function test_place_order_unresolved_symbol_throws(): void
    {
        Http::fake([
            'https://api.megabull.in/api/marketwatch/instruments' => Http::response([
                'downloadUrl' => 'https://megabull-open.s3.us-east-1.amazonaws.com/Instrument-default.csv',
            ], 200),
            'https://megabull-open.s3.us-east-1.amazonaws.com/*' => Http::response(
                "\"tradingSymbol\",\"instrumentName\",\"instrumentToken\"\n\"RELIANCE\",\"RELIANCE\",\"738561\"\n",
                200,
            ),
        ]);

        $adapter = app(BrokerManager::class)->activeAdapter($this->account);

        $this->expectExceptionMessage('MegaBull has no instrument token for symbol UNKNOWNXYZ');

        $adapter->placeOrder(['side' => 'buy', 'quantity' => 1, 'stock' => 'UNKNOWNXYZ', 'price' => 100]);
    }

    public function test_cancel_order_puts_order_id_array(): void
    {
        Http::fake([
            'https://api.megabull.in/api/order/bulk/cancel' => Http::response([
                ['id' => 1001, 'status' => 'SUCCESS', 'msg' => 'Cancelled'],
            ], 200),
        ]);

        $adapter = app(BrokerManager::class)->activeAdapter($this->account);

        $result = $adapter->cancelOrder('1001');

        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.megabull.in/api/order/bulk/cancel'
                && $request->method() === 'PUT'
                && $request->data() === [1001];
        });

        $this->assertTrue($result['ok']);
    }

    public function test_get_positions_combines_positions_and_holdings_and_skips_zero_quantity(): void
    {
        Http::fake([
            'https://api.megabull.in/api/position/my' => Http::response([
                ['instrumentName' => 'TCS', 'qty' => 0, 'priceAvg' => 0, 'pl' => 0],
            ], 200),
            // Our strategy trades CNC (delivery) — confirmed live that a
            // filled order settles here, not /api/position/my.
            'https://api.megabull.in/api/holding/my' => Http::response([
                ['instrumentName' => 'RELIANCE', 'qty' => 10, 'priceAvg' => 2500, 'pl' => 150],
            ], 200),
        ]);

        $adapter = app(BrokerManager::class)->activeAdapter($this->account);

        $positions = $adapter->getPositions();

        $this->assertCount(1, $positions);
        $this->assertSame('RELIANCE', $positions[0]['symbol']);
        $this->assertSame(10.0, $positions[0]['quantity']);
        $this->assertSame(2500.0, $positions[0]['avg_price']);
        $this->assertSame(150.0, $positions[0]['pnl']);
    }

    public function test_get_balances_maps_virtual_money_fields(): void
    {
        Http::fake([
            'https://api.megabull.in/api/user/my' => Http::response([
                'virtualMoney' => 500000,
                'virtualMoneyBlocked' => 25000,
                'virtualMoneyLeft' => 475000,
            ], 200),
        ]);

        $adapter = app(BrokerManager::class)->activeAdapter($this->account);

        $balances = $adapter->getBalances();

        $this->assertSame(475000.0, $balances['available_cash']);
        $this->assertSame(25000.0, $balances['invested']);
    }

    public function test_get_order_status_finds_order_in_open_or_executed(): void
    {
        Http::fake([
            'https://api.megabull.in/api/order/my' => Http::response([
                'open' => [
                    ['id' => 1001, 'status' => 'OPEN', 'qty' => 0, 'price' => 0],
                ],
                'executed' => [
                    ['id' => 1002, 'status' => 'COMPLETE', 'qty' => 5, 'price' => 2500],
                ],
            ], 200),
        ]);

        $adapter = app(BrokerManager::class)->activeAdapter($this->account);

        $status = $adapter->getOrderStatus('1002');

        $this->assertSame('filled', $status['status']);
        $this->assertSame(5.0, $status['filled_qty']);
        $this->assertSame(2500.0, $status['avg_price']);
    }

    public function test_is_api_available_reflects_user_endpoint(): void
    {
        Http::fake([
            'https://api.megabull.in/api/user/my' => Http::response(['firstName' => 'Test'], 200),
        ]);

        $adapter = app(BrokerManager::class)->activeAdapter($this->account);

        $this->assertTrue($adapter->isApiAvailable());
    }

    public function test_megabull_is_listed_as_active_and_flagged_paper(): void
    {
        Passport::actingAs($this->user);

        $brokers = collect($this->getJson('/api/brokers')->json('data'));
        $megabull = $brokers->firstWhere('slug', 'megabull');

        $this->assertNotNull($megabull);
        $this->assertTrue($megabull['paper']);
    }

    public function test_megabull_connect_persists_api_key_and_keeps_paper_mode(): void
    {
        Cache::flush();
        Passport::actingAs($this->user);

        $account = TradingAccount::factory()->for($this->user)->create();

        Http::fake([
            'https://api.megabull.in/api/user/my' => Http::response([
                'firstName' => 'Hemil',
                'lastName' => 'D',
                'virtualMoney' => 500000,
            ], 200),
        ]);

        $response = $this->postJson("/api/broker/connect/{$account->id}/megabull", [
            'api_key' => 'real-key-123',
        ]);

        $response->assertOk()->assertJsonPath('success', true);

        $account->refresh();

        $this->assertSame('real-key-123', $account->getCredential('api_key'));
        $this->assertSame('Hemil D', $account->getCredential('account_name'));
        $this->assertSame('paper', $account->mode, 'MegaBull trades virtual money — account stays in paper mode');
        $this->assertSame($this->megabull->id, $account->broker_id);
        $this->assertTrue($account->isLiveBrokerConnected());
    }

    public function test_megabull_connect_rejects_invalid_key(): void
    {
        Passport::actingAs($this->user);

        $account = TradingAccount::factory()->for($this->user)->create();

        Http::fake([
            'https://api.megabull.in/api/user/my' => Http::response([
                'path' => '/api/user/my',
                'error' => 'AuthenticationException',
                'message' => ['Invalid api-key'],
                'status' => 'UNAUTHORIZED',
            ], 401),
        ]);

        $response = $this->postJson("/api/broker/connect/{$account->id}/megabull", [
            'api_key' => 'bad-key',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'MegaBull key verification failed: Invalid api-key');
    }

    public function test_broker_status_reports_connected_for_megabull(): void
    {
        Passport::actingAs($this->user);

        $status = $this->getJson("/api/broker/status/{$this->account->id}")->json('data');

        $this->assertTrue($status['connected']);
        $this->assertSame('megabull', $status['broker']['slug']);
        $this->assertSame('paper', $status['mode']);
    }
}
