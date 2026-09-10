<?php

namespace App\Models;

use Database\Factories\StockFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 */
class Stock extends Model
{
    /** @use HasFactory<StockFactory> */
    use HasFactory;

    use HasUuids;

    protected $fillable = [
        'symbol', 'name', 'exchange', 'active', 'in_watchlist',
        'sector', 'lot_size', 'under_surveillance', 'yfinance_symbol',
    ];

    protected $casts = [
        'active' => 'boolean',
        'in_watchlist' => 'boolean',
        'under_surveillance' => 'boolean',
        'lot_size' => 'decimal:0',
    ];

    /**
     * @return HasMany<MarketData, $this>
     */
    public function marketData(): HasMany
    {
        return $this->hasMany(MarketData::class);
    }

    /**
     * @return HasMany<MarketIndicator, $this>
     */
    public function indicators(): HasMany
    {
        return $this->hasMany(MarketIndicator::class);
    }

    /**
     * @return HasMany<TradingSignal, $this>
     */
    public function signals(): HasMany
    {
        return $this->hasMany(TradingSignal::class);
    }

    public function latestClose(): ?float
    {
        return MarketData::where('stock_id', $this->id)->orderByDesc('trade_date')->value('close');
    }
}
