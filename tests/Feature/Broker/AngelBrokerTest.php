<?php

namespace Tests\Feature\Broker;

use App\Contracts\Brokers\AngelBroker;
use App\Models\Broker;
use App\Models\TradingAccount;
use App\Models\User;
use App\Services\BrokerManager;
use App\Services\BrokerOAuthService;
use App\Services\TradingConfigService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Passport\Passport;
use Tests\TestCase;

class AngelBrokerTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private TradingAccount $account;

    private Broker $angel;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);

        $this->user = User::factory()->create();
        $this->account = TradingAccount::factory()->for($this->user)->connectedAngel()->create();
        $this->angel = Broker::where('slug', 'angel')->firstOrFail();

        config([
            'brokers.angel.api_key' => 'test-api-key',
            'brokers.angel.api_base' => 'https://apiconnect.angelone.in',
            'brokers.angel.login_url' => 'https://smartapi.angelone.in/publisher-login',
            'brokers.angel.redirect_url' => 'http://localhost/api/broker/angel/callback',
        ]);
    }

    public function test_broker_manager_resolves_angel_adapter_for_account(): void
    {
        $adapter = app(BrokerManager::class)->activeAdapter($this->account);

        $this->assertSame('angel', $adapter->slug());
        $this->assertInstanceOf(AngelBroker::class, $adapter);
    }

    public function test_place_order_posts_smartapi_payload_and_returns_order_id(): void
    {
        Http::fake([
            'https://apiconnect.angelone.in/*' => Http::response([
                'status' => true,
                'message' => 'SUCCESS',
                'errorcode' => null,
                'data' => ['orderid' => '220924002378287'],
            ], 200),
        ]);

        $adapter = app(BrokerManager::class)->activeAdapter($this->account);

        $result = $adapter->placeOrder([
            'side' => 'buy',
            'quantity' => 10,
            'stock' => 'SBIN',
            'type' => 'market',
        ]);

        Http::assertSent(function ($request) {
            $body = $request->data();

            return $request->url() === 'https://apiconnect.angelone.in/rest/secure/angelbroking/order/v1/placeOrder'
                && $request->hasHeader('X-PrivateKey', 'test-api-key')
                && $request->hasHeader('Authorization', 'Bearer fake-angel-auth-token')
                && $body['tradingsymbol'] === 'SBIN-EQ'
                && $body['symboltoken'] === '3045'
                && $body['transactiontype'] === 'BUY'
                && $body['ordertype'] === 'MARKET'
                && $body['quantity'] === 10
                && $body['price'] === '0';
        });

        $this->assertSame('220924002378287', $result['order_ref']);
        $this->assertSame('acknowledged', $result['status']);
    }

    public function test_place_order_rejects_unknown_symbol(): void
    {
        Http::fake();

        $adapter = app(BrokerManager::class)->activeAdapter($this->account);

        $this->expectExceptionMessage('No Angel symboltoken mapped');

        $adapter->placeOrder([
            'side' => 'buy',
            'quantity' => 1,
            'stock' => 'UNKNOWN',
            'type' => 'market',
        ]);
    }

    public function test_place_order_requires_connected_token(): void
    {
        $account = TradingAccount::factory()->for($this->user)->create();

        $broker = Broker::where('slug', 'angel')->first();
        $adapter = new AngelBroker($account, $broker, app(TradingConfigService::class));

        $this->expectExceptionMessage('Angel auth token is missing');

        $adapter->placeOrder(['side' => 'buy', 'quantity' => 1, 'stock' => 'SBIN']);
    }

    public function test_cancel_order_submits_and_inspects_failure(): void
    {
        Http::fake([
            'https://apiconnect.angelone.in/rest/secure/angelbroking/order/v1/cancelOrder' => Http::response([
                'status' => false,
                'message' => 'Invalid order id',
                'errorcode' => 'AG8103',
            ], 200),
        ]);

        $adapter = app(BrokerManager::class)->activeAdapter($this->account);

        $result = $adapter->cancelOrder('220924002378287');

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('Invalid order id', (string) ($result['message'] ?? ''));
    }

    public function test_get_positions_maps_angel_net_records(): void
    {
        Http::fake([
            'https://apiconnect.angelone.in/rest/secure/angelbroking/order/v1/getPosition' => Http::response([
                'status' => true,
                'message' => 'SUCCESS',
                'errorcode' => null,
                'data' => [
                    'net' => [
                        [
                            'tradingsymbol' => 'SBIN-EQ',
                            'exchange' => 'NSE',
                            'quantity' => 10,
                            'averageprice' => 780.5,
                            'pnl' => 215.0,
                        ],
                    ],
                    'day' => [],
                ],
            ], 200),
        ]);

        $adapter = app(BrokerManager::class)->activeAdapter($this->account);

        $positions = $adapter->getPositions();

        $this->assertCount(1, $positions);
        $this->assertEquals('SBIN-EQ', $positions[0]['symbol']);
        $this->assertEquals(10, $positions[0]['quantity']);
        $this->assertEquals(780.5, $positions[0]['avg_price']);
        $this->assertEquals(215.0, $positions[0]['pnl'] ?? 0);
    }

    public function test_get_balances_maps_rms_fields(): void
    {
        Http::fake([
            'https://apiconnect.angelone.in/rest/secure/angelbroking/user/v1/getRMS' => Http::response([
                'status' => true,
                'message' => 'SUCCESS',
                'errorcode' => null,
                'data' => [
                    'net' => 100000,
                    'availablecash' => 25000,
                    'marginused' => 75000,
                    'collateral' => 0,
                ],
            ], 200),
        ]);

        $adapter = app(BrokerManager::class)->activeAdapter($this->account);

        $balances = $adapter->getBalances();

        $this->assertEquals(25000, $balances['available_cash']);
        $this->assertEquals(75000, $balances['invested']);
    }

    public function test_get_order_status_filters_order_book(): void
    {
        Http::fake([
            'https://apiconnect.angelone.in/rest/secure/angelbroking/order/v1/getOrderBook' => Http::response([
                'status' => true,
                'message' => 'SUCCESS',
                'errorcode' => null,
                'data' => [
                    [
                        'orderid' => '220924002378287',
                        'status' => 'complete',
                        'filledqty' => 10,
                        'averageprice' => 780.0,
                        'statusmessage' => 'Order completed.',
                    ],
                    [
                        'orderid' => '220924002378288',
                        'status' => 'open',
                        'filledqty' => 0,
                        'averageprice' => 0,
                        'statusmessage' => 'Order pending.',
                    ],
                ],
            ], 200),
        ]);

        $adapter = app(BrokerManager::class)->activeAdapter($this->account);

        $status = $adapter->getOrderStatus('220924002378287');

        $this->assertEquals('filled', $status['status']);
        $this->assertEquals(10, $status['filled_qty']);
        $this->assertEquals(780.0, $status['avg_price']);
    }

    public function test_is_api_available_reflects_profile_call(): void
    {
        Http::fake([
            'https://apiconnect.angelone.in/rest/secure/angelbroking/user/v1/getProfile' => Http::response([
                'status' => true,
                'message' => 'SUCCESS',
                'errorcode' => null,
                'data' => ['clientcode' => 'A000000'],
            ], 200),
        ]);

        $adapter = app(BrokerManager::class)->activeAdapter($this->account);

        $this->assertTrue($adapter->isApiAvailable());
    }

    public function test_is_api_available_false_on_failure(): void
    {
        Http::fake([
            'https://apiconnect.angelone.in/rest/secure/angelbroking/user/v1/getProfile' => Http::response([
                'status' => false,
                'message' => 'Token Expired',
                'errorcode' => 'AG8002',
            ], 200),
        ]);

        $adapter = app(BrokerManager::class)->activeAdapter($this->account);

        $this->assertFalse($adapter->isApiAvailable());
    }

    public function test_callback_redirect_persists_angel_tokens(): void
    {
        $state = $this->makeState($this->account);

        $this->getJson(
            "/api/broker/angel/callback?auth_token=angel-jwt&feed_token=angel-feed&client_id=A001&state={$state}"
        )->assertStatus(302)->assertRedirect();

        $this->account->refresh();

        $this->assertSame('angel-jwt', $this->account->getAccessToken());
        $this->assertSame('angel-feed', $this->account->getCredential('feed_token'));
        $this->assertSame('A001', $this->account->getCredential('client_id'));
        $this->assertSame('live', $this->account->mode);
        $this->assertSame($this->angel->id, $this->account->broker_id);
    }

    public function test_connect_returns_publisher_login_url_for_angel(): void
    {
        Passport::actingAs($this->user);

        $response = $this->getJson("/api/broker/connect/{$this->account->id}/angel");

        $response->assertOk()
            ->assertJsonPath('success', true);

        $url = $response->json('data.authorization_url');

        $this->assertStringContainsString('https://smartapi.angelone.in/publisher-login', $url);
        $this->assertStringContainsString('api_key=test-api-key', $url);
        $this->assertStringContainsString('state=', $url);
        $this->assertStringContainsString('redirect_url=', $url);
    }

    public function test_angel_broker_is_listed_as_active(): void
    {
        Passport::actingAs($this->user);

        $slugs = $this->getJson('/api/brokers')->json('data');

        $this->assertTrue(
            is_array($slugs) && in_array('angel', array_column($slugs, 'slug'), true)
        );
    }

    private function makeState(TradingAccount $account): string
    {
        $service = $this->app->make(BrokerOAuthService::class);

        $method = new \ReflectionMethod($service, 'buildState');

        return $method->invoke($service, $account);
    }
}
