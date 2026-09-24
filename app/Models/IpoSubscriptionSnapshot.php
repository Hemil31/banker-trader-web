<?php

namespace App\Models;

use Database\Factories\IpoSubscriptionSnapshotFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A point-in-time subscription snapshot for one IPO, keyed by `as_on`
 * (typically the 05:00 PM close-of-day figure from the source).
 *
 * @property string $id
 */
class IpoSubscriptionSnapshot extends Model
{
    /** @use HasFactory<IpoSubscriptionSnapshotFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'ipo_subscription_snapshots';

    protected $fillable = [
        'ipo_id', 'as_on', 'qib', 'nii', 'bhni', 'shni', 'retail', 'employee', 'total',
    ];

    protected $casts = [
        'as_on' => 'datetime',
        'qib' => 'float',
        'nii' => 'float',
        'bhni' => 'float',
        'shni' => 'float',
        'retail' => 'float',
        'employee' => 'float',
        'total' => 'float',
    ];

    /**
     * @return BelongsTo<Ipo, $this>
     */
    public function ipo(): BelongsTo
    {
        return $this->belongsTo(Ipo::class);
    }
}
