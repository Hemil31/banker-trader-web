<?php

namespace Tests\Feature\AiPostGeneration;

use App\Contracts\Gemini\GeminiClient;
use App\Contracts\Zernio\ZernioClient;
use App\Exceptions\Gemini\GeminiException;
use App\Exceptions\Gemini\GeminiRetryableException;
use App\Jobs\GenerateAiPostJob;
use App\Models\AiPostRequest;
use App\Models\TradingConfig;
use App\Models\ZernioAccount;
use App\Services\Gemini\GeminiPostGenerationService;
use App\Services\Zernio\ZernioService;
use DateTimeInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class GenerateAiPostJobTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        TradingConfig::pluckDefaults();
    }

    protected function fakeGemini(FakeGeminiClient $fake): void
    {
        $this->app->instance(GeminiClient::class, $fake);
    }

    protected function fakeZernio(): FakeZernioClient
    {
        $fake = new FakeZernioClient;
        $this->app->instance(ZernioClient::class, $fake);

        return $fake;
    }

    protected function runJob(AiPostRequest $request, int $tries = 5): void
    {
        $job = new GenerateAiPostJob($request->id);
        $job->tries = $tries;
        $job->handle(app(GeminiPostGenerationService::class), app(ZernioService::class), app(GeminiClient::class));
    }

    public function test_successful_generation_saves_content_and_hands_off_to_zernio(): void
    {
        $account = ZernioAccount::factory()->create(['zernio_account_id' => 'acc-1', 'platform' => 'instagram']);
        $request = AiPostRequest::factory()->create(['zernio_account_id' => $account->id]);

        $this->fakeGemini(new FakeGeminiClient(json_encode([
            'caption' => 'It is a great day to trade.',
            'hashtags' => ['trading', 'markets'],
            'cta' => 'Download the app',
            'content_type' => 'promotional',
        ])));
        $zernio = $this->fakeZernio();
        $zernio->postResult = ['id' => 'zp-99', 'status' => 'scheduled', 'platforms' => []];
        Http::fake();

        $this->runJob($request);

        $request->refresh();
        $this->assertSame(AiPostRequest::STATUS_SCHEDULED, $request->status);
        $this->assertSame('It is a great day to trade.', $request->caption);
        $this->assertSame(['#trading', '#markets'], $request->hashtags);
        $this->assertNotNull($request->zernio_post_id);
        $this->assertSame('zp-99', $request->zernioPost->zernio_post_id);
        $this->assertSame(1, $request->attempts);
        $this->assertCount(1, $zernio->calls['createPost'] ?? []);
        $this->assertSame(
            'https://media.example.test/post.png',
            $zernio->calls['createPost'][0]['media'][0]['url'],
        );
    }

    public function test_a_request_that_already_succeeded_never_calls_gemini_again(): void
    {
        $account = ZernioAccount::factory()->create();
        $request = AiPostRequest::factory()->create([
            'zernio_account_id' => $account->id,
            'status' => AiPostRequest::STATUS_SCHEDULED,
        ]);

        $fake = new FakeGeminiClient('should not be called');
        $this->fakeGemini($fake);
        $this->fakeZernio();

        $this->runJob($request);

        $this->assertSame(0, $fake->callCount);
        $this->assertSame(AiPostRequest::STATUS_SCHEDULED, $request->fresh()->status);
    }

    public function test_a_retryable_failure_keeps_the_request_retryable_when_attempts_remain(): void
    {
        $account = ZernioAccount::factory()->create();
        $request = AiPostRequest::factory()->create(['zernio_account_id' => $account->id]);

        $this->fakeGemini(new FakeGeminiClient(null, new GeminiRetryableException('Gemini rate limit / quota exceeded (HTTP 429)')));
        $this->fakeZernio();

        $this->runJob($request, tries: 5);

        $request->refresh();
        $this->assertNotSame(AiPostRequest::STATUS_FAILED, $request->status);
        $this->assertStringContainsString('429', (string) $request->last_error);
        $this->assertSame(1, $request->attempts);
    }

    public function test_a_retryable_failure_is_marked_failed_once_the_retry_budget_is_exhausted(): void
    {
        $account = ZernioAccount::factory()->create();
        $request = AiPostRequest::factory()->create(['zernio_account_id' => $account->id]);

        $this->fakeGemini(new FakeGeminiClient(null, new GeminiRetryableException('Gemini server error (HTTP 503)')));
        $this->fakeZernio();

        // tries=1 + no queue Job attached means attempts() reports 1, so the
        // very first failure already exhausts the (tiny, test-only) budget.
        $this->runJob($request, tries: 1);

        $request->refresh();
        $this->assertSame(AiPostRequest::STATUS_FAILED, $request->status);
        $this->assertStringContainsString('503', (string) $request->last_error);
    }

    public function test_a_non_retryable_failure_fails_the_request_immediately(): void
    {
        $account = ZernioAccount::factory()->create();
        $request = AiPostRequest::factory()->create(['zernio_account_id' => $account->id]);

        $this->fakeGemini(new FakeGeminiClient(null, new RuntimeException('Gemini API key is not configured.')));
        $this->fakeZernio();

        $this->runJob($request, tries: 5);

        $request->refresh();
        $this->assertSame(AiPostRequest::STATUS_FAILED, $request->status);
        $this->assertSame('Gemini API key is not configured.', $request->last_error);
    }

    public function test_a_zernio_failure_after_successful_generation_marks_the_request_failed_but_keeps_the_content(): void
    {
        $account = ZernioAccount::factory()->create();
        $request = AiPostRequest::factory()->create(['zernio_account_id' => $account->id]);

        $this->fakeGemini(new FakeGeminiClient(json_encode([
            'caption' => 'Caption', 'hashtags' => ['x'], 'cta' => 'Go', 'content_type' => 'promo',
        ])));
        $zernio = $this->fakeZernio();
        $zernio->throwOnCreate = new RuntimeException('Zernio unreachable');
        Http::fake(['upload.example.test/*' => Http::response('ok', 200)]);

        $this->runJob($request);

        $request->refresh();
        $this->assertSame(AiPostRequest::STATUS_FAILED, $request->status);
        $this->assertStringContainsString('Zernio unreachable', $request->last_error);
        $this->assertSame('Caption', $request->caption);
    }

    public function test_an_image_generation_failure_fails_the_request_without_scheduling(): void
    {
        $account = ZernioAccount::factory()->create(['platform' => 'instagram']);
        $request = AiPostRequest::factory()->create(['zernio_account_id' => $account->id]);

        $fake = new FakeGeminiClient(json_encode([
            'caption' => 'Caption', 'hashtags' => ['x'], 'cta' => 'Go', 'content_type' => 'promo',
        ]));
        $fake->imageThrow = new GeminiException('Image model returned no image.');
        $this->fakeGemini($fake);
        $this->fakeZernio();
        Http::fake();

        $this->runJob($request);

        $request->refresh();
        $this->assertSame(AiPostRequest::STATUS_FAILED, $request->status);
        $this->assertStringContainsString('Image generation failed', $request->last_error);
        $this->assertSame('Caption', $request->caption);
    }
}

class FakeGeminiClient implements GeminiClient
{
    public int $callCount = 0;

    public ?string $lastPrompt = null;

    public mixed $imageThrow = null;

    public function __construct(protected ?string $responseText, protected ?\Throwable $throw = null) {}

    public function generate(string $prompt, array $responseSchema = []): array
    {
        $this->callCount++;
        $this->lastPrompt = $prompt;

        if ($this->throw !== null) {
            throw $this->throw;
        }

        return ['text' => (string) $this->responseText, 'model' => 'gemini-flash-lite-latest', 'raw' => []];
    }

    /**
     * @return array{bytes: string, mime: string, model: string}
     */
    public function generateImage(string $prompt): array
    {
        if ($this->imageThrow !== null) {
            throw $this->imageThrow;
        }

        return ['bytes' => 'fake-image-bytes', 'mime' => 'image/png', 'model' => 'gemini-image'];
    }
}

class FakeZernioClient implements ZernioClient
{
    /** @var array<string, array<int, mixed>> */
    public array $calls = [];

    /** @var array<string, mixed>|null */
    public ?array $postResult = null;

    public mixed $throwOnCreate = null;

    public function listAccounts(): array
    {
        return [];
    }

    public function createPost(
        string $content,
        array $targets,
        array $media = [],
        ?DateTimeInterface $scheduledAt = null,
        ?string $timezone = null,
        ?string $idempotencyKey = null,
    ): array {
        $this->calls['createPost'][] = compact('content', 'targets', 'media', 'scheduledAt');

        if ($this->throwOnCreate !== null) {
            throw $this->throwOnCreate;
        }

        return $this->postResult ?? ['id' => 'zp-x', 'status' => 'scheduled', 'platforms' => []];
    }

    public function getPost(string $postId): array
    {
        return ['id' => $postId, 'status' => 'pending', 'platforms' => []];
    }

    public function requestPresignedUpload(string $filename, string $contentType, int $size = 0): array
    {
        return [
            'upload_url' => 'https://upload.example.test/media',
            'public_url' => 'https://media.example.test/post.png',
            'key' => 'k',
            'expires_in' => 300,
        ];
    }
}
