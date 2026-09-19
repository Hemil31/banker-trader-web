<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\AiPostRequestFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One generation slot: "on this date, for this Zernio account, in this content
 * category, there should be a post." Created ahead of time (by an admin action
 * or the `posts:generate` scheduler), then carried through
 * pending -> generating -> generated -> scheduled -> published by
 * GenerateAiPostJob, or -> failed on an unrecoverable error.
 *
 * The (scheduled_date, zernio_account_id, content_category) unique index is
 * the idempotency guarantee: creating a request always goes through
 * firstOrCreate() on those columns, so the same slot never gets a second row,
 * and the job itself no-ops on a request that already reached a terminal
 * success status instead of calling Gemini again.
 *
 * @property string $id
 */
class AiPostRequest extends Model
{
    /** @use HasFactory<AiPostRequestFactory> */
    use HasFactory;

    use HasUuids;

    public const STATUS_PENDING = 'pending';

    public const STATUS_GENERATING = 'generating';

    public const STATUS_GENERATED = 'generated';

    public const STATUS_SCHEDULED = 'scheduled';

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_FAILED = 'failed';

    /** Statuses that mean "Gemini already produced usable content for this slot." */
    public const SUCCESSFUL_STATUSES = [
        self::STATUS_GENERATED,
        self::STATUS_SCHEDULED,
        self::STATUS_PUBLISHED,
    ];

    protected $table = 'ai_post_requests';

    protected $fillable = [
        'scheduled_date', 'scheduled_time', 'zernio_account_id', 'content_category',
        'title', 'prompt', 'status', 'attempts', 'festival_name', 'festival_type',
        'caption', 'hashtags', 'cta', 'content_type', 'gemini_model', 'raw_response',
        'last_error', 'zernio_post_id', 'created_by',
    ];

    protected $casts = [
        'scheduled_date' => 'date',
        'attempts' => 'integer',
        'hashtags' => 'array',
        'raw_response' => 'array',
    ];

    /**
     * @return BelongsTo<ZernioAccount, $this>
     */
    public function zernioAccount(): BelongsTo
    {
        return $this->belongsTo(ZernioAccount::class);
    }

    /**
     * @return BelongsTo<ZernioPost, $this>
     */
    public function zernioPost(): BelongsTo
    {
        return $this->belongsTo(ZernioPost::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function hasSucceeded(): bool
    {
        return in_array($this->status, self::SUCCESSFUL_STATUSES, true);
    }

    /**
     * The exact local datetime to hand to Zernio as `scheduled_at`.
     */
    public function scheduledAt(): CarbonImmutable
    {
        $time = (string) $this->scheduled_time;

        return $this->scheduled_date->copy()->setTimeFromTimeString($time);
    }
}
