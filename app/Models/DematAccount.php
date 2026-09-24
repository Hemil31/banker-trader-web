<?php

namespace App\Models;

use Database\Factories\DematAccountFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A depository (NSDL/CDSL) demat account linked to a user — the BO the
 * allotted shares land in. The (provider, client_id) pair identifies the BO.
 *
 * @property string $id
 */
class DematAccount extends Model
{
    /** @use HasFactory<DematAccountFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'demat_accounts';

    protected $fillable = [
        'user_id', 'provider', 'dp_id', 'client_id', 'account_name', 'upi_id',
        'status', 'verified_at', 'verification_details', 'is_primary',
    ];

    protected $casts = [
        'verified_at' => 'datetime',
        'verification_details' => 'array',
        'is_primary' => 'boolean',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<IpoApplication, $this>
     */
    public function applications(): HasMany
    {
        return $this->hasMany(IpoApplication::class);
    }
}
