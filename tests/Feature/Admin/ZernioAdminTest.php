<?php

namespace Tests\Feature\Admin;

use App\Contracts\Zernio\ZernioClient;
use App\Exceptions\ZernioException;
use App\Models\TradingConfig;
use App\Models\User;
use App\Models\ZernioAccount;
use App\Models\ZernioPost;
use Database\Seeders\DatabaseSeeder;
use DateTimeInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ZernioAdminTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        TradingConfig::pluckDefaults();
    }

    protected function fakeClient(): FakeZernioClient
    {
        $fake = new FakeZernioClient;

        $this->app->instance(ZernioClient::class, $fake);

        return $fake;
    }

    protected function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    public function test_guests_are_redirected_away_from_zernio_pages(): void
    {
        $this->get(route('admin.zernio.accounts'))->assertRedirect(route('login'));
        $this->get(route('admin.zernio.posts'))->assertRedirect(route('login'));
    }

    public function test_regular_users_cannot_use_zernio_pages(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get(route('admin.zernio.accounts'))->assertForbidden();
        $this->get(route('admin.zernio.posts'))->assertForbidden();
    }

    public function test_admin_can_view_the_accounts_page(): void
    {
        $admin = $this->admin();
        ZernioAccount::factory()->create([
            'platform' => 'x',
            'name' => 'Trader Alix',
            'username' => 'traderalix',
        ]);

        $this->actingAs($admin);

        $response = $this->get(route('admin.zernio.accounts'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('admin/zernio/accounts')
            ->has('accounts', 1)
        );
    }

    public function test_sync_accounts_upserts_the_local_mirror(): void
    {
        $fake = $this->fakeClient();
        $fake->accounts = [
            [
                'id' => 'acc-1',
                'platform' => 'x',
                'name' => 'Trader Alix',
                'username' => 'traderalix',
                'avatar_url' => null,
                'is_active' => true,
                'needs_reconnection' => false,
            ],
            [
                'id' => 'acc-2',
                'platform' => 'instagram',
                'name' => 'Signals Room',
                'username' => null,
                'avatar_url' => 'https://img/avatar.png',
                'is_active' => true,
                'needs_reconnection' => true,
            ],
        ];

        $this->actingAs($this->admin());

        $this->post(route('admin.zernio.accounts.sync'))->assertRedirect();

        $this->assertSame(2, ZernioAccount::count());
        $this->assertTrue(
            ZernioAccount::where('zernio_account_id', 'acc-2')->first()->needs_reconnection
        );

        $this->assertDatabaseHas('zernio_accounts', ['zernio_account_id' => 'acc-1', 'is_active' => true]);
    }

    public function test_sync_marks_accounts_removed_on_zernio_as_inactive(): void
    {
        $fake = $this->fakeClient();
        ZernioAccount::factory()->create(['zernio_account_id' => 'acc-ghost']);
        $fake->accounts = [];

        $this->actingAs($this->admin());

        $this->post(route('admin.zernio.accounts.sync'))->assertRedirect();

        $this->assertDatabaseHas('zernio_accounts', ['zernio_account_id' => 'acc-ghost', 'is_active' => false]);
    }

    public function test_admin_can_view_the_posts_page(): void
    {
        $this->actingAs($this->admin());

        $response = $this->get(route('admin.zernio.posts'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('admin/zernio/posts')
            ->has('posts')
            ->where('timezone', config('zernio.timezone'))
        );
    }

    public function test_sync_lists_new_accounts_against_an_existing_mirror(): void
    {
        $fake = $this->fakeClient();
        $fake->accounts = [
            [
                'id' => 'acc-1',
                'platform' => 'x',
                'name' => 'Trader Alix',
                'username' => 'traderalix',
                'avatar_url' => null,
                'is_active' => true,
                'needs_reconnection' => false,
            ],
        ];

        $this->actingAs($this->admin());
        $this->post(route('admin.zernio.accounts.sync'))->assertRedirect();

        $this->post(route('admin.zernio.accounts.sync'))->assertRedirect();

        $this->assertSame(1, ZernioAccount::count());
    }

    public function test_admin_can_create_an_immediate_post_for_selected_accounts(): void
    {
        $fake = $this->fakeClient();
        $account = ZernioAccount::factory()->create([
            'zernio_account_id' => 'acc-1',
            'platform' => 'x',
        ]);
        $fake->postResult = [
            'id' => 'zp-1',
            'status' => 'published',
            'platforms' => [
                ['platform' => 'x', 'account_id' => 'acc-1', 'status' => 'published', 'platform_post_url' => 'https://x.com/1'],
            ],
        ];

        $this->actingAs($this->admin());

        $response = $this->post(route('admin.zernio.posts.store'), [
            'content' => 'Hello world',
            'account_ids' => [$account->id],
        ]);

        $response->assertRedirect();

        $post = ZernioPost::firstOrFail();
        $this->assertSame('Hello world', $post->content);
        $this->assertTrue($post->publish_now);
        $this->assertSame('published', $post->status);
        $this->assertSame('zp-1', $post->zernio_post_id);
        $this->assertDatabaseHas('zernio_post_account', [
            'zernio_post_id' => $post->id,
            'zernio_account_id' => $account->id,
            'status' => 'published',
            'platform_post_url' => 'https://x.com/1',
        ]);
    }

    public function test_admin_can_schedule_a_post(): void
    {
        $fake = $this->fakeClient();
        $account = ZernioAccount::factory()->create(['zernio_account_id' => 'acc-1']);

        $this->actingAs($this->admin());

        $this->post(route('admin.zernio.posts.store'), [
            'content' => 'Scheduled post',
            'account_ids' => [$account->id],
            'scheduled_at' => '2026-09-20T10:30',
            'timezone' => 'Asia/Kolkata',
        ])->assertRedirect();

        $this->assertNotNull($fake->lastScheduledAt);
        $this->assertSame('Asia/Kolkata', $fake->lastTimezone);
    }

    public function test_store_post_fails_validation_with_no_accounts(): void
    {
        $this->fakeClient();
        $this->actingAs($this->admin());

        $this->post(route('admin.zernio.posts.store'), [
            'content' => 'Hello',
            'account_ids' => [],
        ])->assertSessionHasErrors('account_ids');
    }

    public function test_failed_post_call_is_persisted_as_failed(): void
    {
        $fake = $this->fakeClient();
        $account = ZernioAccount::factory()->create(['zernio_account_id' => 'acc-1']);
        $fake->throwOnCreate = new \RuntimeException('Zernio unreachable');

        $this->actingAs($this->admin());

        $response = $this->post(route('admin.zernio.posts.store'), [
            'content' => 'Will fail',
            'account_ids' => [$account->id],
        ]);

        $response->assertSessionHasErrors('post');

        $post = ZernioPost::firstOrFail();
        $this->assertSame('failed', $post->status);
        $this->assertSame('Zernio unreachable', $post->error);
        $this->assertDatabaseHas('zernio_post_account', [
            'zernio_post_id' => $post->id,
            'zernio_account_id' => $account->id,
            'status' => 'failed',
        ]);
    }

    public function test_presign_media_returns_the_upload_target_as_json(): void
    {
        $fake = $this->fakeClient();
        $fake->presigned = [
            'upload_url' => 'https://cdn.zernio.com/put/abc',
            'public_url' => 'https://cdn.zernio.com/abc.png',
            'key' => 'abc',
            'expires_in' => 3600,
        ];

        $this->actingAs($this->admin());

        $response = $this->post(route('admin.zernio.media.presign'), [
            'filename' => 'chart.png',
            'content_type' => 'image/png',
            'size' => 5000,
        ]);

        $response->assertOk();
        $response->assertJson([
            'upload_url' => 'https://cdn.zernio.com/put/abc',
            'public_url' => 'https://cdn.zernio.com/abc.png',
        ]);
        $this->assertSame('chart.png', $fake->lastPresignFilename);
    }

    public function test_presign_media_rejects_unsupported_file_types(): void
    {
        $this->fakeClient();
        $this->actingAs($this->admin());

        $this->post(route('admin.zernio.media.presign'), [
            'filename' => 'malware.exe',
            'content_type' => 'application/x-msdownload',
        ])->assertSessionHasErrors('content_type');
    }

    public function test_admin_settings_include_zernio_keys(): void
    {
        $this->seed(DatabaseSeeder::class);
        $admin = $this->admin();

        $response = $this->actingAs($admin)->get(route('admin.settings'));

        $response->assertOk();

        /** @var array<int, array{key: string}> $settings */
        $settings = $response->inertiaProps('settings');

        $keys = array_column($settings, 'key');

        $this->assertContains('zernio.api_key', $keys);
        $this->assertContains('zernio.timezone', $keys);
    }

    public function test_refresh_updates_the_post_status_from_zernio(): void
    {
        $fake = $this->fakeClient();
        $account = ZernioAccount::factory()->create(['zernio_account_id' => 'acc-1']);
        $post = ZernioPost::factory()->create([
            'status' => 'pending',
            'zernio_post_id' => 'zp-1',
            'error' => null,
        ]);
        $post->accounts()->attach($account->id, ['status' => 'processing']);
        $fake->postLookup = [
            'id' => 'zp-1',
            'status' => 'published',
            'platforms' => [
                ['platform' => 'x', 'account_id' => 'acc-1', 'status' => 'published', 'platform_post_url' => 'https://x.com/p/9'],
            ],
        ];

        $this->actingAs($this->admin());

        $this->post(route('admin.zernio.posts.refresh', $post))->assertRedirect();

        $this->assertSame('published', $post->fresh()->status);
        $this->assertDatabaseHas('zernio_post_account', [
            'zernio_post_id' => $post->id,
            'zernio_account_id' => $account->id,
            'status' => 'published',
            'platform_post_url' => 'https://x.com/p/9',
        ]);
    }

    public function test_refresh_records_a_platform_error_message_on_failure(): void
    {
        $fake = $this->fakeClient();
        $account = ZernioAccount::factory()->create(['zernio_account_id' => 'acc-1']);
        $post = ZernioPost::factory()->create([
            'status' => 'pending',
            'zernio_post_id' => 'zp-1',
            'error' => null,
        ]);
        $post->accounts()->attach($account->id, ['status' => 'processing']);
        $fake->postLookup = [
            'id' => 'zp-1',
            'status' => 'failed',
            'platforms' => [
                ['platform' => 'x', 'account_id' => 'acc-1', 'status' => 'failed', 'platform_post_url' => null, 'error_message' => 'Reddit requires a subreddit.'],
            ],
        ];

        $this->actingAs($this->admin());

        $this->post(route('admin.zernio.posts.refresh', $post))->assertRedirect();

        $post = $post->fresh();
        $this->assertSame('failed', $post->status);
        $this->assertSame('Reddit requires a subreddit.', $post->error);
        $this->assertDatabaseHas('zernio_post_account', [
            'zernio_post_id' => $post->id,
            'zernio_account_id' => $account->id,
            'status' => 'failed',
        ]);
    }

    public function test_refresh_is_a_no_op_for_posts_that_never_reached_zernio(): void
    {
        $fake = $this->fakeClient();
        $post = ZernioPost::factory()->create([
            'status' => 'failed',
            'zernio_post_id' => null,
            'error' => 'offline',
        ]);

        $this->actingAs($this->admin());

        $this->post(route('admin.zernio.posts.refresh', $post))->assertRedirect();

        $this->assertArrayNotHasKey('getPost', $fake->calls);
        $this->assertSame('failed', $post->fresh()->status);
        $this->assertSame('offline', $post->fresh()->error);
    }

    public function test_refresh_reports_a_zernio_error(): void
    {
        $fake = $this->fakeClient();
        $post = ZernioPost::factory()->create([
            'status' => 'pending',
            'zernio_post_id' => 'zp-1',
            'error' => null,
        ]);
        $fake->throwOnGet = new ZernioException('Zernio down');

        $this->actingAs($this->admin());

        $this->post(route('admin.zernio.posts.refresh', $post))
            ->assertSessionHasErrors('refresh');
    }

    public function test_refresh_returns_404_for_an_unknown_post(): void
    {
        $this->fakeClient();
        $this->actingAs($this->admin());

        $this->post(route('admin.zernio.posts.refresh', 'missing-id'))->assertNotFound();
    }

    public function test_regular_users_cannot_sync_or_post(): void
    {
        $this->actingAs(User::factory()->create());

        $this->post(route('admin.zernio.accounts.sync'))->assertForbidden();
        $this->post(route('admin.zernio.posts.store'))->assertForbidden();
        $this->post(route('admin.zernio.media.presign'))->assertForbidden();
    }

    public function test_refresh_is_forbidden_for_regular_users(): void
    {
        $this->fakeClient();
        $post = ZernioPost::factory()->create(['zernio_post_id' => 'zp-1']);

        $this->actingAs(User::factory()->create());

        $this->post(route('admin.zernio.posts.refresh', $post))->assertForbidden();
    }
}

class FakeZernioClient implements ZernioClient
{
    /** @var array<int, array<string, mixed>> */
    public array $accounts = [];

    /** @var array<string, array<int, mixed>> */
    public array $calls = [];

    /** @var array<string, mixed>|null */
    public ?array $postResult = null;

    /** @var array<string, mixed>|null */
    public ?array $postLookup = null;

    public mixed $throwOnGet = null;

    public ?DateTimeInterface $lastScheduledAt = null;

    public ?string $lastTimezone = null;

    public ?string $lastPresignFilename = null;

    public mixed $throwOnCreate = null;

    /** @var array<string, mixed> */
    public array $presigned = ['upload_url' => 'https://cdn/up', 'public_url' => 'https://cdn/pub'];

    public function listAccounts(): array
    {
        $this->calls['listAccounts'][] = true;

        return $this->accounts;
    }

    public function createPost(
        string $content,
        array $targets,
        array $media = [],
        ?DateTimeInterface $scheduledAt = null,
        ?string $timezone = null,
        ?string $idempotencyKey = null,
    ): array {
        $this->calls['createPost'][] = compact('content', 'targets', 'media', 'scheduledAt', 'timezone', 'idempotencyKey');
        $this->lastScheduledAt = $scheduledAt;
        $this->lastTimezone = $timezone;

        if ($this->throwOnCreate !== null) {
            throw $this->throwOnCreate;
        }

        return $this->postResult ?? [
            'id' => 'zp-x',
            'status' => 'pending',
            'platforms' => [],
        ];
    }

    public function getPost(string $postId): array
    {
        $this->calls['getPost'][] = $postId;

        if ($this->throwOnGet !== null) {
            throw $this->throwOnGet;
        }

        return $this->postLookup ?? $this->postResult ?? ['id' => $postId, 'status' => 'pending', 'platforms' => []];
    }

    public function requestPresignedUpload(string $filename, string $contentType, int $size = 0): array
    {
        $this->calls['requestPresignedUpload'][] = compact('filename', 'contentType', 'size');
        $this->lastPresignFilename = $filename;

        return $this->presigned;
    }
}
