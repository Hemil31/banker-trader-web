<?php

namespace App\Models;

use Database\Factories\ZernioAccountFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A social-media account connected at zernio.com and mirrored locally so the
 * admin console can pick targets for posts without calling Zernio each time.
 *
 * @property string $id
 * @property-read ZernioPostAccount|null $pivot
 */
class ZernioAccount extends Model
{
    /** @use HasFactory<ZernioAccountFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'zernio_accounts';

    protected $fillable = [
        'zernio_account_id', 'platform', 'name', 'username',
        'avatar_url', 'is_active', 'needs_reconnection',
        'raw_payload', 'synced_at',
    ];

    protected $casts = [
        'avatar_url' => 'string',
        'is_active' => 'boolean',
        'needs_reconnection' => 'boolean',
        'raw_payload' => 'json',
        'synced_at' => 'datetime',
    ];

    /**
     * @return BelongsToMany<ZernioPost, $this, ZernioPostAccount, 'pivot'>
     */
    public function posts(): BelongsToMany
    {
        return $this->belongsToMany(ZernioPost::class, 'zernio_post_account')
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
            'zernio_account_id' => $this->zernio_account_id,
            'platform' => $this->platform,
            'name' => $this->name,
            'username' => $this->username,
            'avatar_url' => $this->avatar_url,
            'is_active' => $this->is_active,
            'needs_reconnection' => $this->needs_reconnection,
            'synced_at' => $this->synced_at?->toIso8601String(),
        ];
    }
}
