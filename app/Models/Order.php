<?php

namespace App\Models;

use Database\Factories\OrderFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 */
class Order extends Model
{
    /** @use HasFactory<OrderFactory> */
    use HasFactory;

    use HasUuids;

    protected $fillable = [
        'trading_account_id', 'stock_id', 'broker_id', 'trading_signal_id',
        'order_ref', 'side', 'type', 'status',
        'requested_quantity', 'filled_quantity', 'price', 'avg_fill_price',
        'slippage', 'purpose', 'trailing_mode', 'trailing_value',
        'request_payload', 'response_payload', 'failure_reason',
        'requested_at', 'filled_at', 'timeout_at',
    ];

    protected $casts = [
        'requested_quantity' => 'decimal:0',
        'filled_quantity' => 'decimal:0',
        'price' => 'decimal:4',
        'avg_fill_price' => 'decimal:4',
        'slippage' => 'decimal:4',
        'trailing_value' => 'decimal:4',
        'request_payload' => 'json',
        'response_payload' => 'json',
        'requested_at' => 'datetime',
        'filled_at' => 'datetime',
        'timeout_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<TradingAccount, $this>
     */
    public function tradingAccount(): BelongsTo
    {
        return $this->belongsTo(TradingAccount::class);
    }

    /**
     * @return BelongsTo<Stock, $this>
     */
    public function stock(): BelongsTo
    {
        return $this->belongsTo(Stock::class);
    }

    /**
     * @return BelongsTo<TradingSignal, $this>
     */
    public function tradingSignal(): BelongsTo
    {
        return $this->belongsTo(TradingSignal::class);
    }

    /**
     * @return HasMany<OrderExecution, $this>
     */
    public function executions(): HasMany
    {
        return $this->hasMany(OrderExecution::class);
    }

    public function isFullyFilled(): bool
    {
        return (float) $this->filled_quantity >= (float) $this->requested_quantity;
    }
}
