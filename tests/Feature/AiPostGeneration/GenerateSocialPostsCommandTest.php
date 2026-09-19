<?php

namespace Tests\Feature\AiPostGeneration;

use App\Jobs\GenerateAiPostJob;
use App\Models\AiPostRequest;
use App\Models\ZernioAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class GenerateSocialPostsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_one_slot_per_active_account_per_day_and_queues_it(): void
    {
        Queue::fake();

        $active = ZernioAccount::factory()->create(['is_active' => true]);
        ZernioAccount::factory()->inactive()->create();

        $this->artisan('posts:generate', ['--date' => '2026-10-20'])->assertSuccessful();

        $this->assertSame(1, AiPostRequest::count());
        $this->assertDatabaseHas('ai_post_requests', [
            'zernio_account_id' => $active->id,
            'scheduled_date' => '2026-10-20',
            'content_category' => 'general',
            'status' => AiPostRequest::STATUS_PENDING,
        ]);
        Queue::assertPushed(GenerateAiPostJob::class, 1);
    }

    public function test_running_it_twice_never_creates_a_duplicate_slot_or_recalls_gemini_for_it(): void
    {
        Queue::fake();
        ZernioAccount::factory()->create(['is_active' => true]);

        $this->artisan('posts:generate', ['--date' => '2026-10-20'])->assertSuccessful();
        AiPostRequest::query()->update(['status' => AiPostRequest::STATUS_GENERATED]);

        $this->artisan('posts:generate', ['--date' => '2026-10-20'])->assertSuccessful();

        $this->assertSame(1, AiPostRequest::count());
        // Already generated -> not dispatched again.
        Queue::assertPushed(GenerateAiPostJob::class, 1);
    }

    public function test_retry_failed_flag_requeues_failed_slots_but_not_by_default(): void
    {
        Queue::fake();
        $account = ZernioAccount::factory()->create(['is_active' => true]);
        AiPostRequest::factory()->failed()->create([
            'zernio_account_id' => $account->id,
            'scheduled_date' => '2026-10-20',
        ]);

        $this->artisan('posts:generate', ['--date' => '2026-10-20'])->assertSuccessful();
        Queue::assertNotPushed(GenerateAiPostJob::class);

        $this->artisan('posts:generate', ['--date' => '2026-10-20', '--retry-failed' => true])->assertSuccessful();
        Queue::assertPushed(GenerateAiPostJob::class, 1);
    }

    public function test_it_covers_a_date_range_for_today_and_tomorrow_shortcuts(): void
    {
        Queue::fake();
        ZernioAccount::factory()->create(['is_active' => true]);

        $this->artisan('posts:generate', ['--today' => true])->assertSuccessful();
        $this->artisan('posts:generate', ['--tomorrow' => true])->assertSuccessful();

        $this->assertSame(2, AiPostRequest::count());
        $this->assertDatabaseHas('ai_post_requests', ['scheduled_date' => now()->toDateString()]);
        $this->assertDatabaseHas('ai_post_requests', ['scheduled_date' => now()->addDay()->toDateString()]);
    }

    public function test_it_reports_no_accounts_without_failing(): void
    {
        Queue::fake();

        $this->artisan('posts:generate', ['--date' => '2026-10-20'])->assertSuccessful();

        $this->assertSame(0, AiPostRequest::count());
        Queue::assertNothingPushed();
    }
}
