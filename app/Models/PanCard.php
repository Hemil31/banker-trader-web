<?php

namespace App\Models;

use Database\Factories\PanCardFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A PAN card linked to a user — the identity under which every IPO
 * application is made and allotment is checked.
 *
 * @property string $id
 */
class PanCard extends Model
{
    /** @use HasFactory<PanCardFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'pan_cards';

    protected $fillable = [
        'user_id', 'pan_number', 'holder_name', 'date_of_birth',
        'status', 'verified_at', 'verification_details', 'is_primary',
    ];

    protected $casts = [
        'date_of_birth' => 'date',
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
