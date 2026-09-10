<?php

namespace App\Models;

use Database\Factories\MarketIndicatorFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 */
class MarketIndicator extends Model
{
    /** @use HasFactory<MarketIndicatorFactory> */
    use HasFactory;

    use HasUuids;

    protected $fillable = [
        'stock_id', 'trade_date', 'rsi14', 'atr14', 'ma20', 'ma50', 'ma200',
        'ret3d', 'ret5d', 'dist_from_20d_high', 'volume_ratio',
        'avg_volume_20d', 'daily_range_pct', 'beta',
    ];

    protected $casts = [
        'trade_date' => 'date',
        'rsi14' => 'float',
        'atr14' => 'float',
        'ma20' => 'float',
        'ma50' => 'float',
        'ma200' => 'float',
        'ret3d' => 'float',
        'ret5d' => 'float',
        'dist_from_20d_high' => 'float',
        'volume_ratio' => 'float',
        'avg_volume_20d' => 'float',
        'daily_range_pct' => 'float',
        'beta' => 'float',
    ];

    /**
     * @return BelongsTo<Stock, $this>
     */
    public function stock(): BelongsTo
    {
        return $this->belongsTo(Stock::class);
    }
}
