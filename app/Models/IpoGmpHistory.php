<?php

namespace App\Models;

use Database\Factories\IpoGmpHistoryFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A day-wise grey-market premium quote for one IPO, keyed by `recorded_at`.
 *
 * @property string $id
 */
class IpoGmpHistory extends Model
{
    /** @use HasFactory<IpoGmpHistoryFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'ipo_gmp_history';

    protected $fillable = [
        'ipo_id', 'recorded_at', 'gmp', 'premium_pct', 'indicative_price',
    ];

    protected $casts = [
        'recorded_at' => 'datetime',
        'gmp' => 'float',
        'premium_pct' => 'float',
        'indicative_price' => 'float',
    ];

    /**
     * @return BelongsTo<Ipo, $this>
     */
    public function ipo(): BelongsTo
    {
        return $this->belongsTo(Ipo::class);
    }
}
