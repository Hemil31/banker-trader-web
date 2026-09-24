<?php

namespace App\Models;

use Database\Factories\IpoAllotmentFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The allotment result for one IPO application (one row per application,
 * updated in place on each check). Source is a pluggable AllotmentProvider.
 *
 * @property string $id
 */
class IpoAllotment extends Model
{
    /** @use HasFactory<IpoAllotmentFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'ipo_allotments';

    protected $fillable = [
        'ipo_application_id', 'ipo_id', 'pan_number', 'result',
        'shares_allotted', 'registrar', 'source', 'attempts', 'checked_at', 'raw_payload',
    ];

    protected $casts = [
        'shares_allotted' => 'integer',
        'attempts' => 'integer',
        'checked_at' => 'datetime',
        'raw_payload' => 'array',
    ];

    /**
     * @return BelongsTo<IpoApplication, $this>
     */
    public function application(): BelongsTo
    {
        return $this->belongsTo(IpoApplication::class, 'ipo_application_id');
    }

    /**
     * @return BelongsTo<Ipo, $this>
     */
    public function ipo(): BelongsTo
    {
        return $this->belongsTo(Ipo::class);
    }
}
