<?php

namespace Tests\Feature\Broker;

use App\Contracts\Brokers\UpstoxBroker;
use App\Models\Broker;
use App\Models\TradingAccount;
use App\Models\User;
use App\Services\BrokerManager;
use App\Services\BrokerOAuthService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Passport\Passport;
use Tests\TestCase;

class BrokerApiTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private TradingAccount $account;

    private Broker $upstox;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);

        $this->user = User::factory()->create();
        $this->account = TradingAccount::factory()->for($this->user)->create();
        $this->upstox = Broker::where('slug', 'upstox')->firstOrFail();

        config([
            'brokers.upstox.app_id' => 'test-app-id',
            'brokers.upstox.app_secret' => 'test-app-secret',
            'brokers.upstox.redirect_url' => 'http://localhost/api/broker/upstox/callback',
            'brokers.upstox.sandbox' => false,
        ]);
    }

    public function test_brokers_list_requires_authentication(): void
    {
        $this->getJson('/api/brokers')->assertUnauthorized();
    }

    public function test_brokers_list_returns_active_brokers(): void
    {
        Passport::actingAs($this->user);

        $this->getJson('/api/brokers')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => [['slug', 'name', 'paper', 'active']]]);
    }

    public function test_connect_returns_authorization_url_for_owned_account(): void
    {
        Passport::actingAs($this->user);

        $response = $this->getJson("/api/broker/connect/{$this->account->id}/upstox");

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => ['authorization_url']]);

        $url = $response->json('data.authorization_url');
        $this->assertStringContainsString('client_id', $url);
        $this->assertStringContainsString('state', $url);
    }

    public function test_connect_rejects_account_belonging_to_another_user(): void
    {
        $other = User::factory()->create();
        $foreignAccount = TradingAccount::factory()->for($other)->create();

        Passport::actingAs($this->user);

        $this->getJson("/api/broker/connect/{$foreignAccount->id}/upstox")->assertNotFound();
    }

    public function test_connect_rejects_unknown_broker_slug(): void
    {
        Passport::actingAs($this->user);

        $this->getJson("/api/broker/connect/{$this->account->id}/nonexistent")->assertNotFound();
    }

    public function test_status_reports_not_connected_by_default(): void
    {
        Passport::actingAs($this->user);

        $this->getJson("/api/broker/status/{$this->account->id}")
            ->assertOk()
            ->assertJsonPath('data.connected', false);
    }

    public function test_callback_exchanges_code_and_persists_tokens(): void
    {
        Http::fake([
            config('brokers.upstox.token_url') => Http::response([
                'access_token' => 'abc-123',
                'refresh_token' => 'refresh-abc',
                'expires_in' => 86400,
                'token_type' => 'Bearer',
            ], 200),
        ]);

        $state = $this->makeState($this->account);

        $response = $this->getJson("/api/broker/upstox/callback?code=CODE123&state={$state}");

        $response->assertStatus(302)
            ->assertRedirect();

        $this->account->refresh();

        $this->assertSame('abc-123', $this->account->getAccessToken());
        $this->assertSame('live', $this->account->mode);
        $this->assertSame($this->upstox->id, $this->account->broker_id);
    }

    public function test_callback_rejects_invalid_state(): void
    {
        Http::fake();

        $this->getJson('/api/broker/upstox/callback?code=CODE123&state=invalid')
            ->assertStatus(302)
            ->assertRedirect();
    }

    public function test_disconnect_clears_credentials_and_returns_to_paper(): void
    {
        config(['brokers.upstox.redirect_success_base' => 'bankertrader://broker/connected']);

        $connected = TradingAccount::factory()
            ->for($this->user)
            ->connectedUpstox()
            ->create();

        Passport::actingAs($this->user);

        $this->deleteJson("/api/broker/disconnect/{$connected->id}")
            ->assertOk()
            ->assertJsonPath('success', true);

        $connected->refresh();

        $this->assertNull($connected->broker_id);
        $this->assertNull($connected->credentials);
        $this->assertSame('paper', $connected->mode);
    }

    public function test_broker_manager_resolves_upstox_adapter_for_account(): void
    {
        config(['brokers.upstox.sandbox' => false]);

        $connected = TradingAccount::factory()
            ->for($this->user)
            ->connectedUpstox()
            ->create();

        $adapter = app(BrokerManager::class)->activeAdapter($connected);

        $this->assertSame('upstox', $adapter->slug());
        $this->assertInstanceOf(UpstoxBroker::class, $adapter);
    }

    public function test_broker_manager_falls_back_to_paper_for_unconnected_account(): void
    {
        $adapter = app(BrokerManager::class)->activeAdapter($this->account);

        $this->assertSame('paper', $adapter->slug());
    }

    public function test_accounts_returns_user_accounts_with_connection_state(): void
    {
        TradingAccount::factory()
            ->for($this->user)
            ->connectedUpstox()
            ->create(['name' => 'Live']);

        Passport::actingAs($this->user);

        $this->getJson('/api/broker/accounts')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'data' => [[
                    'id',
                    'name',
                    'connected',
                    'broker',
                    'mode',
                ]],
            ])
            ->assertJsonPath('data.0.id', $this->account->id)
            ->assertJsonPath('data.0.connected', false)
            ->assertJsonPath('data.1.name', 'Live')
            ->assertJsonPath('data.1.connected', true)
            ->assertJsonPath('data.1.broker.slug', 'upstox')
            ->assertJsonPath('data.1.mode', 'live');
    }

    public function test_feed_rejects_paper_account(): void
    {
        Passport::actingAs($this->user);

        $this->getJson("/api/broker/feed/{$this->account->id}/market")
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_feed_rejects_account_belonging_to_another_user(): void
    {
        $other = User::factory()->create();
        $foreignAccount = TradingAccount::factory()->for($other)->create();

        Passport::actingAs($this->user);

        $this->getJson("/api/broker/feed/{$foreignAccount->id}/market")->assertNotFound();
    }

    public function test_feed_returns_authorized_uri_for_connected_account(): void
    {
        $adapter = \Mockery::mock(UpstoxBroker::class);
        $adapter->shouldReceive('marketDataFeedUri')
            ->once()
            ->andReturn('wss://ws.upstox.com/v3/feed?token=feed-token');

        $manager = \Mockery::mock(BrokerManager::class);
        $manager->shouldReceive('activeAdapter')->once()->andReturn($adapter);

        $this->app->instance(BrokerManager::class, $manager);

        Passport::actingAs($this->user);

        $this->getJson("/api/broker/feed/{$this->account->id}/market")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.uri', 'wss://ws.upstox.com/v3/feed?token=feed-token')
            ->assertJsonPath('data.type', 'market');
    }

    private function makeState(TradingAccount $account): string
    {
        $service = $this->app->make(BrokerOAuthService::class);

        $method = new \ReflectionMethod($service, 'buildState');

        return $method->invoke($service, $account);
    }
}
