<?php

namespace App\Models;

use Database\Factories\MarketDataFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 */
class MarketData extends Model
{
    /** @use HasFactory<MarketDataFactory> */
    use HasFactory;

    use HasUuids;

    protected $fillable = [
        'stock_id', 'trade_date', 'open', 'high', 'low', 'close',
        'adjusted_close', 'volume', 'turnover',
    ];

    protected $casts = [
        'trade_date' => 'date',
        'open' => 'decimal:4',
        'high' => 'decimal:4',
        'low' => 'decimal:4',
        'close' => 'decimal:4',
        'adjusted_close' => 'decimal:4',
        'volume' => 'integer',
        'turnover' => 'float',
    ];

    /**
     * @return BelongsTo<Stock, $this>
     */
    public function stock(): BelongsTo
    {
        return $this->belongsTo(Stock::class);
    }
}
