<?php

namespace App\Jobs;

use App\Contracts\Gemini\GeminiClient;
use App\Exceptions\Gemini\GeminiException;
use App\Exceptions\Gemini\GeminiRetryableException;
use App\Exceptions\ZernioException;
use App\Models\AiPostRequest;
use App\Services\Gemini\GeminiPostGenerationService;
use App\Services\Zernio\ZernioService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Turns one AiPostRequest slot into generated content and, on success, hands
 * it to the existing Zernio scheduling flow. This is the only place that
 * calls Gemini for a given request.
 *
 * Rate limiting / sequencing (per the "never fire many Gemini requests at
 * once" requirement):
 *  - `RateLimited('gemini-generation')` throttles execution to the
 *    configured model's RPM/RPD (see AppServiceProvider::boot()) — jobs over
 *    the limit are released back to the queue with a delay rather than run.
 *  - `WithoutOverlapping` guarantees at most one generation runs at a time
 *    even with multiple queue workers, so calls are always sequential:
 *    generate -> save -> schedule -> (next).
 *
 * Retries: $tries + backoff() give exponential backoff on retryable Gemini
 * failures (429 / 5xx / malformed JSON). Non-retryable failures call
 * $this->fail() immediately instead of burning the retry budget.
 */
class GenerateAiPostJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 5;

    public function __construct(public string $aiPostRequestId) {}

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [
            new RateLimited('gemini-generation'),
            new WithoutOverlapping('gemini-post-generation'),
        ];
    }

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [30, 60, 120, 300, 600];
    }

    public function handle(GeminiPostGenerationService $service, ZernioService $zernio, GeminiClient $gemini): void
    {
        $request = AiPostRequest::find($this->aiPostRequestId);
        if (! $request) {
            return;
        }

        if ($request->hasSucceeded()) {
            Log::info('POST_ALREADY_EXISTS', $this->context($request));

            return;
        }

        $request->increment('attempts');
        $request->update(['status' => AiPostRequest::STATUS_GENERATING]);

        Log::info('POST_GENERATION_STARTED', $this->context($request));

        try {
            $generated = $service->generate($request);
        } catch (GeminiRetryableException $e) {
            $this->handleRetryable($request, $e);

            return;
        } catch (Throwable $e) {
            $request->update([
                'status' => AiPostRequest::STATUS_FAILED,
                'last_error' => $e->getMessage(),
            ]);
            Log::error('POST_GENERATION_FAILED', $this->context($request, ['error' => $e->getMessage()]));

            return;
        }

        $request->update([
            'status' => AiPostRequest::STATUS_GENERATED,
            'caption' => $generated['caption'],
            'hashtags' => $generated['hashtags'],
            'cta' => $generated['cta'],
            'content_type' => $generated['content_type'],
            'festival_name' => $generated['festival_name'],
            'festival_type' => $generated['festival_type'],
            'gemini_model' => $generated['model'],
            'raw_response' => $generated['raw'],
            'last_error' => null,
        ]);

        Log::info('POST_GENERATION_SUCCESS', $this->context($request));

        $this->scheduleWithZernio($request->fresh(), $zernio, $gemini);
    }

    /**
     * A retryable Gemini failure (429 / 5xx / bad JSON): log it, and either
     * let Laravel's automatic queue retry (backoff()) take over, or mark the
     * request permanently failed once the retry budget is exhausted.
     */
    protected function handleRetryable(AiPostRequest $request, GeminiRetryableException $e): void
    {
        $context = $this->context($request, ['error' => $e->getMessage()]);

        if (str_contains($e->getMessage(), '429') || str_contains(strtolower($e->getMessage()), 'rate limit') || str_contains(strtolower($e->getMessage()), 'quota')) {
            Log::warning('GEMINI_RATE_LIMIT', $context);
        } else {
            Log::warning('GEMINI_RETRY', $context);
        }

        if ($this->attempts() >= $this->tries) {
            $request->update([
                'status' => AiPostRequest::STATUS_FAILED,
                'last_error' => $e->getMessage(),
            ]);
            Log::error('POST_GENERATION_FAILED', $this->context($request, ['error' => $e->getMessage(), 'exhausted' => true]));

            return;
        }

        $request->update(['last_error' => $e->getMessage()]);

        // Rethrow so Laravel releases the job back onto the queue using
        // backoff() instead of considering it done.
        $this->release($this->backoff()[min($this->attempts() - 1, count($this->backoff()) - 1)]);
    }

    /**
     * Media-required platforms (Instagram) reject text-only posts, so the job
     * generates a fresh, real image for each post via the Gemini image model
     * and uploads it through the Zernio presign flow — no static/placeholder
     * artwork anywhere. No-op for platforms that don't require media.
     *
     * @return array<int, array{url: string, type: string}>
     */
    protected function generatedMedia(GeminiClient $gemini, ZernioService $zernio, AiPostRequest $request): array
    {
        if (strtolower((string) $request->zernioAccount?->platform) !== 'instagram') {
            return [];
        }

        $image = $gemini->generateImage($this->imagePrompt($request));

        $filename = str_contains($image['mime'], 'jpeg')
            ? 'post-'.$request->scheduled_date->format('Ymd').'.jpg'
            : 'post-'.$request->scheduled_date->format('Ymd').'.png';

        $upload = $zernio->presignMedia($filename, $image['mime'], strlen($image['bytes']));

        $uploaded = Http::timeout((int) config('zernio.timeout', 15))
            ->withBody($image['bytes'], $image['mime'])
            ->put($upload['upload_url']);

        if (! $uploaded->successful()) {
            throw new ZernioException('Failed to upload the generated post image (HTTP '.$uploaded->status().').');
        }

        return [['url' => (string) $upload['public_url'], 'type' => 'image']];
    }

    /**
     * Builds the image-generation prompt from the slot's own content so the
     * artwork is unique to each post: title, category and the caption itself.
     */
    protected function imagePrompt(AiPostRequest $request): string
    {
        $theme = (string) ($request->caption ?: 'financial trading and the markets today');

        return 'Create one professional, square (1:1) social-media post image for a financial trading brand. '
            ."Post title: {$request->title}. Category: {$request->content_category}. "
            .'Theme drawn from this caption: '.mb_substr($theme, 0, 160)
            .'. Style: premium and minimal, deep navy background with subtle gold accents, '
            .'clean modern financial aesthetic. No text overlays, no brand name, no logos, no watermark.';
    }

    /**
     * Pass a successfully generated post into the existing Zernio scheduling
     * flow (ZernioService::createPost) — this is the only integration point
     * with Zernio; nothing about ZernioService itself changes.
     */
    protected function scheduleWithZernio(AiPostRequest $request, ZernioService $zernio, GeminiClient $gemini): void
    {
        $content = trim($request->caption."\n\n".implode(' ', (array) $request->hashtags)."\n\n".$request->cta);

        try {
            $result = $zernio->createPost(
                content: $content,
                accountIds: [$request->zernio_account_id],
                media: $this->generatedMedia($gemini, $zernio, $request),
                scheduledAt: $request->scheduledAt()->toDateTimeString(),
                createdBy: $request->created_by,
            );

            $request->update([
                'status' => AiPostRequest::STATUS_SCHEDULED,
                'zernio_post_id' => $result['id'] ?? null,
            ]);

            Log::info('POST_SCHEDULED', $this->context($request));
        } catch (GeminiRetryableException $e) {
            $this->handleRetryable($request, $e);
        } catch (GeminiException $e) {
            $request->update([
                'status' => AiPostRequest::STATUS_FAILED,
                'last_error' => 'Image generation failed: '.$e->getMessage(),
            ]);

            Log::error('POST_GENERATION_FAILED', $this->context($request, ['stage' => 'image_generation', 'error' => $e->getMessage()]));
        } catch (Throwable $e) {
            $request->update([
                'status' => AiPostRequest::STATUS_FAILED,
                'last_error' => 'Zernio scheduling failed: '.$e->getMessage(),
            ]);

            Log::error('POST_GENERATION_FAILED', $this->context($request, ['stage' => 'zernio_schedule', 'error' => $e->getMessage()]));
        }
    }

    public function failed(?Throwable $e): void
    {
        $request = AiPostRequest::find($this->aiPostRequestId);
        if (! $request) {
            return;
        }

        $request->update([
            'status' => AiPostRequest::STATUS_FAILED,
            'last_error' => $e?->getMessage() ?? 'Unknown error',
        ]);

        Log::error('POST_GENERATION_FAILED', $this->context($request, [
            'error' => $e?->getMessage(),
            'exhausted' => true,
        ]));
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    protected function context(AiPostRequest $request, array $extra = []): array
    {
        return array_merge([
            'post_id' => $request->id,
            'scheduled_date' => $request->scheduled_date->toDateString(),
            'content_category' => $request->content_category,
            'zernio_account_id' => $request->zernio_account_id,
            'attempt' => $this->attempts(),
        ], $extra);
    }
}
