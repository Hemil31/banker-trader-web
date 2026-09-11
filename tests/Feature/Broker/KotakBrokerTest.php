<?php

namespace Tests\Feature\Broker;

use App\Contracts\Brokers\KotakBroker;
use App\Models\Broker;
use App\Models\TradingAccount;
use App\Models\User;
use App\Services\BrokerManager;
use App\Services\TradingConfigService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Passport\Passport;
use Tests\TestCase;

class KotakBrokerTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private TradingAccount $account;

    private Broker $kotak;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);

        $this->user = User::factory()->create();
        $this->account = TradingAccount::factory()->for($this->user)->connectedKotak()->create();
        $this->kotak = Broker::where('slug', 'kotak')->firstOrFail();

        config([
            'brokers.kotak.consumer_key' => 'test-consumer-key',
            'brokers.kotak.api_base' => 'https://mis.kotaksecurities.com',
        ]);
    }

    public function test_broker_manager_resolves_kotak_adapter_for_account(): void
    {
        $adapter = app(BrokerManager::class)->activeAdapter($this->account);

        $this->assertSame('kotak', $adapter->slug());
        $this->assertInstanceOf(KotakBroker::class, $adapter);
    }

    public function test_place_order_posts_jdata_payload_and_returns_order_number(): void
    {
        Http::fake([
            'https://e2.kotaksecurities.com/*' => Http::response(['stat' => 'Ok', 'nOrdNo' => '121212'], 200),
        ]);

        $adapter = app(BrokerManager::class)->activeAdapter($this->account);

        $result = $adapter->placeOrder([
            'side' => 'buy',
            'quantity' => 10,
            'stock' => 'SBIN',
            'type' => 'market',
        ]);

        Http::assertSent(function ($request) {
            $body = json_decode((string) ($request['jData'] ?? ''), true);

            return $request->url() === 'https://e2.kotaksecurities.com/quick/order/rule/ms/place'
                && $request->hasHeader('Authorization', 'test-consumer-key')
                && $request->hasHeader('Sid', 'fake-kotak-sid')
                && $request->hasHeader('Auth', 'fake-kotak-token')
                && $body['es'] === 'nse_cm'
                && $body['pc'] === 'MIS'
                && $body['pt'] === 'MKT'
                && $body['ts'] === 'SBIN-EQ'
                && $body['tt'] === 'B'
                && $body['qt'] === 10
                && $body['pr'] === '0'
                && $body['tp'] === '0'
                && $body['rt'] === 'DAY'
                && $body['mp'] === '0'
                && $body['am'] === 'NO'
                && $body['os'] === 'NEOTRADEAPI';
        });

        $this->assertSame('121212', $result['order_ref']);
        $this->assertSame('acknowledged', $result['status']);
    }

    public function test_place_order_limit_sets_price_and_order_type(): void
    {
        Http::fake([
            'https://e2.kotaksecurities.com/*' => Http::response(['stat' => 'Ok', 'nOrdNo' => '121214'], 200),
        ]);

        $adapter = app(BrokerManager::class)->activeAdapter($this->account);

        $adapter->placeOrder([
            'side' => 'sell',
            'quantity' => 5,
            'stock' => 'RELIANCE',
            'type' => 'limit',
            'price' => 1450.5,
        ]);

        Http::assertSent(function ($request) {
            $body = json_decode((string) ($request['jData'] ?? ''), true);

            return $body['pt'] === 'L' && $body['pr'] === '1450.50' && $body['tt'] === 'S';
        });
    }

    public function test_place_order_requires_connected_session(): void
    {
        $account = TradingAccount::factory()->for($this->user)->create();

        $broker = Broker::where('slug', 'kotak')->first();
        $adapter = new KotakBroker($account, $broker, app(TradingConfigService::class));

        $this->expectExceptionMessage('Kotak session token is missing');

        $adapter->placeOrder(['side' => 'buy', 'quantity' => 1, 'stock' => 'SBIN']);
    }

    public function test_cancel_order_submits_order_number_and_inspects_failure(): void
    {
        Http::fake([
            'https://e2.kotaksecurities.com/quick/order/cancel' => Http::response([
                'stCode' => 1021,
                'errMsg' => 'order is completed',
                'stat' => 'please provide valid order number',
            ], 400),
        ]);

        $adapter = app(BrokerManager::class)->activeAdapter($this->account);

        $result = $adapter->cancelOrder('121212');

        Http::assertSent(function ($request) {
            $body = json_decode((string) ($request['jData'] ?? ''), true);

            return $request->url() === 'https://e2.kotaksecurities.com/quick/order/cancel'
                && $body['on'] === '121212'
                && $body['am'] === 'NO';
        });

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('order is completed', (string) ($result['message'] ?? ''));
    }

    public function test_get_positions_maps_net_quantity_and_avg_price(): void
    {
        Http::fake([
            'https://e2.kotaksecurities.com/quick/user/positions' => Http::response([
                'stat' => 'ok',
                'stCode' => 200,
                'data' => [
                    [
                        'exSeg' => 'nse_cm',
                        'trdSym' => 'SBIN-EQ',
                        'sym' => 'SBIN',
                        'cfBuyQty' => '10',
                        'flBuyQty' => '5',
                        'cfSellQty' => '2',
                        'flSellQty' => '0',
                        'cfBuyAmt' => '7800.00',
                        'buyAmt' => '3900.00',
                        'cfSellAmt' => '1560.00',
                        'sellAmt' => '0.00',
                        'multiplier' => '1',
                        'genNum' => '1',
                        'genDen' => '1',
                        'prcNum' => '1',
                        'prcDen' => '1',
                        'lotSz' => '1',
                    ],
                ],
            ], 200),
        ]);

        $adapter = app(BrokerManager::class)->activeAdapter($this->account);

        $positions = $adapter->getPositions();

        $this->assertCount(1, $positions);
        $this->assertEquals('SBIN-EQ', $positions[0]['symbol']);
        $this->assertEquals(13, $positions[0]['quantity']);
        $this->assertEquals(780.0, $positions[0]['avg_price']);
    }

    public function test_get_balances_maps_limits_fields(): void
    {
        Http::fake([
            'https://e2.kotaksecurities.com/quick/user/limits' => Http::response([
                'stat' => 'Ok',
                'stCode' => 200,
                'Net' => '25000',
                'MarginUsed' => '75000',
            ], 200),
        ]);

        $adapter = app(BrokerManager::class)->activeAdapter($this->account);

        $balances = $adapter->getBalances();

        $this->assertEquals(25000, $balances['available_cash']);
        $this->assertEquals(75000, $balances['invested']);
    }

    public function test_get_order_status_filters_order_book_by_order_number(): void
    {
        Http::fake([
            'https://e2.kotaksecurities.com/quick/user/orders' => Http::response([
                'stat' => 'Ok',
                'stCode' => 200,
                'data' => [
                    [
                        'nOrdNo' => '121212',
                        'ordSt' => 'complete',
                        'fldQty' => 10,
                        'avgPrc' => '780.00',
                        'rejRsn' => '--',
                    ],
                    [
                        'nOrdNo' => '121213',
                        'ordSt' => 'open',
                        'fldQty' => 0,
                        'avgPrc' => '0.00',
                        'rejRsn' => '--',
                    ],
                ],
            ], 200),
        ]);

        $adapter = app(BrokerManager::class)->activeAdapter($this->account);

        $status = $adapter->getOrderStatus('121212');

        $this->assertEquals('filled', $status['status']);
        $this->assertEquals(10, $status['filled_qty']);
        $this->assertEquals(780.0, $status['avg_price']);
    }

    public function test_is_api_available_reflects_positions_call(): void
    {
        Http::fake([
            'https://e2.kotaksecurities.com/quick/user/positions' => Http::response([
                'stat' => 'ok',
                'stCode' => 200,
                'data' => [],
            ], 200),
        ]);

        $adapter = app(BrokerManager::class)->activeAdapter($this->account);

        $this->assertTrue($adapter->isApiAvailable());
    }

    public function test_is_api_available_false_on_failure(): void
    {
        Http::fake([
            'https://e2.kotaksecurities.com/quick/user/positions' => Http::response([
                'stCode' => 403,
                'errMsg' => 'Invalid session',
            ], 403),
        ]);

        $adapter = app(BrokerManager::class)->activeAdapter($this->account);

        $this->assertFalse($adapter->isApiAvailable());
    }

    public function test_kotak_broker_is_listed_as_active(): void
    {
        Passport::actingAs($this->user);

        $slugs = $this->getJson('/api/brokers')->json('data');

        $this->assertTrue(
            is_array($slugs) && in_array('kotak', array_column($slugs, 'slug'), true)
        );
    }

    public function test_kotak_connect_persists_session_credentials(): void
    {
        Passport::actingAs($this->user);

        Http::fake([
            'https://mis.kotaksecurities.com/login/1.0/tradeApiLogin' => Http::response([
                'stat' => 'Ok',
                'data' => [
                    'token' => 'view-token',
                    'sid' => 'view-sid',
                    'ucc' => 'K000000',
                ],
            ], 200),
            'https://mis.kotaksecurities.com/login/1.0/tradeApiValidate' => Http::response([
                'stat' => 'Ok',
                'data' => [
                    'token' => 'edit-token',
                    'sid' => 'edit-sid',
                    'rid' => '1',
                    'dataCenter' => 'DC1',
                    'baseUrl' => 'https://e2.kotaksecurities.com',
                ],
            ], 200),
        ]);

        $response = $this->postJson("/api/broker/connect/{$this->account->id}/kotak", [
            'mobile_number' => '+919000000000',
            'ucc' => 'K000000',
            'totp' => '123456',
            'mpin' => '4321',
        ]);

        $response->assertOk()->assertJsonPath('success', true);

        $this->account->refresh();

        $this->assertSame('edit-token', $this->account->getAccessToken());
        $this->assertSame('edit-sid', $this->account->getCredential('sid'));
        $this->assertSame('https://e2.kotaksecurities.com', $this->account->getCredential('base_url'));
        $this->assertSame('DC1', $this->account->getCredential('data_center'));
        $this->assertSame('live', $this->account->mode);
        $this->assertSame($this->kotak->id, $this->account->broker_id);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://mis.kotaksecurities.com/login/1.0/tradeApiLogin'
                && $request->hasHeader('Authorization', 'test-consumer-key')
                && $request->hasHeader('neo-fin-key', 'neotradeapi')
                && $request['mobileNumber'] === '+919000000000'
                && $request['ucc'] === 'K000000'
                && $request['totp'] === '123456';
        });

        Http::assertSent(function ($request) {
            return $request->url() === 'https://mis.kotaksecurities.com/login/1.0/tradeApiValidate'
                && $request->hasHeader('Sid', 'view-sid')
                && $request->hasHeader('Auth', 'view-token')
                && $request['mpin'] === '4321';
        });
    }

    public function test_kotak_connect_rejects_invalid_login(): void
    {
        Passport::actingAs($this->user);

        Http::fake([
            'https://mis.kotaksecurities.com/login/1.0/tradeApiLogin' => Http::response([
                'stCode' => 401,
                'errMsg' => 'Invalid credentials',
            ], 401),
        ]);

        $response = $this->postJson("/api/broker/connect/{$this->account->id}/kotak", [
            'mobile_number' => '+919000000000',
            'ucc' => 'K000000',
            'totp' => '000000',
            'mpin' => '4321',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Kotak totp_login failed: Invalid credentials');
    }
}
