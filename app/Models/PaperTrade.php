<?php

namespace App\Models;

use Database\Factories\PaperTradeFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 */
class PaperTrade extends Model
{
    /** @use HasFactory<PaperTradeFactory> */
    use HasFactory;

    use HasUuids;

    protected $fillable = [
        'trading_account_id', 'trading_signal_id', 'position_id', 'symbol', 'direction',
        'signal_price', 'intended_entry', 'fill_price', 'quantity',
        'stop_loss', 'target', 'slippage', 'pnl', 'pnl_net', 'status',
        'entry_reason', 'exit_reason', 'signal_at', 'executed_at',
        'exited_at', 'simulation_data',
    ];

    protected $casts = [
        'signal_price' => 'decimal:4',
        'intended_entry' => 'decimal:4',
        'fill_price' => 'decimal:4',
        'quantity' => 'decimal:0',
        'stop_loss' => 'decimal:4',
        'target' => 'decimal:4',
        'slippage' => 'decimal:4',
        'pnl' => 'decimal:2',
        'pnl_net' => 'decimal:2',
        'signal_at' => 'datetime',
        'executed_at' => 'datetime',
        'exited_at' => 'datetime',
        'simulation_data' => 'json',
    ];

    /**
     * @return BelongsTo<TradingAccount, $this>
     */
    public function tradingAccount(): BelongsTo
    {
        return $this->belongsTo(TradingAccount::class);
    }

    /**
     * @return BelongsTo<TradingSignal, $this>
     */
    public function tradingSignal(): BelongsTo
    {
        return $this->belongsTo(TradingSignal::class);
    }

    /**
     * @return BelongsTo<Position, $this>
     */
    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }
}
