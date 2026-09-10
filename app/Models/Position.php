<?php

namespace App\Models;

use Database\Factories\PositionFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 */
class Position extends Model
{
    /** @use HasFactory<PositionFactory> */
    use HasFactory;

    use HasUuids;

    protected $fillable = [
        'trading_account_id', 'stock_id', 'broker_id', 'trading_signal_id', 'status',
        'quantity', 'avg_entry_price', 'entry_value', 'stop_loss',
        'target1', 'target2', 'target3', 'current_stop',
        'realized_pnl', 'realized_pnl_net', 'unrealized_pnl', 'unrealized_pnl_net',
        'partial_booked_qty', 'trailing_enabled',
        'opened_at', 'closed_at', 'close_reason', 'exit_price', 'net_pnl', 'meta',
    ];

    protected $casts = [
        'quantity' => 'decimal:0',
        'avg_entry_price' => 'decimal:4',
        'entry_value' => 'decimal:2',
        'stop_loss' => 'decimal:4',
        'target1' => 'decimal:4',
        'target2' => 'decimal:4',
        'target3' => 'decimal:4',
        'current_stop' => 'decimal:4',
        'realized_pnl' => 'decimal:2',
        'realized_pnl_net' => 'decimal:2',
        'unrealized_pnl' => 'decimal:2',
        'unrealized_pnl_net' => 'decimal:2',
        'partial_booked_qty' => 'decimal:0',
        'trailing_enabled' => 'boolean',
        'opened_at' => 'datetime',
        'closed_at' => 'datetime',
        'exit_price' => 'decimal:4',
        'net_pnl' => 'decimal:2',
        'meta' => 'json',
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

    public function isOpen(): bool
    {
        return $this->status === 'open';
    }
}
