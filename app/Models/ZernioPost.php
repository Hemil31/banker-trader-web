<?php

namespace App\Models;

use Database\Factories\ZernioPostFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A post sent to Zernio (or queued for it). One row per admin compose action;
 * the per-account outcome lives on the zernio_post_account pivot.
 *
 * @property string $id
 */
class ZernioPost extends Model
{
    /** @use HasFactory<ZernioPostFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'zernio_posts';

    protected $fillable = [
        'created_by', 'content', 'publish_now', 'scheduled_at',
        'timezone', 'status', 'zernio_post_id', 'idempotency_key', 'error',
    ];

    protected $casts = [
        'publish_now' => 'boolean',
        'scheduled_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsToMany<ZernioAccount, $this, ZernioPostAccount, 'pivot'>
     */
    public function accounts(): BelongsToMany
    {
        return $this->belongsToMany(ZernioAccount::class, 'zernio_post_account')
            ->using(ZernioPostAccount::class)
            ->withPivot('status', 'platform_post_url')
            ->withTimestamps();
    }

    /**
     * @return array<string, mixed>
     */
    public function toAdminRow(): array
    {
        return [
            'id' => $this->id,
            'content' => $this->content,
            'publish_now' => $this->publish_now,
            'scheduled_at' => $this->scheduled_at?->toIso8601String(),
            'timezone' => $this->timezone,
            'status' => $this->status,
            'zernio_post_id' => $this->zernio_post_id,
            'created_by' => $this->creator->name ?? $this->created_by,
            'error' => $this->error,
            'created_at' => $this->created_at?->toIso8601String(),
            'accounts' => $this->accounts
                ->map(fn (ZernioAccount $a): array => [
                    'id' => $a->id,
                    'name' => $a->name,
                    'platform' => $a->platform,
                    'status' => $a->pivot->status,
                    'platform_post_url' => $a->pivot->platform_post_url,
                ])
                ->values()
                ->all(),
        ];
    }
}
