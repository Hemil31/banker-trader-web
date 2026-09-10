<?php

namespace App\Models;

use Database\Factories\TradingPnlDailyFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 */
class TradingPnlDaily extends Model
{
    /** @use HasFactory<TradingPnlDailyFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'trading_pnl_daily';

    protected $fillable = [
        'trading_account_id', 'trade_date', 'realized', 'unrealized',
        'gross_pnl', 'costs', 'net_pnl', 'charges',
        'trades_count', 'wins', 'losses', 'target_progress',
    ];

    protected $casts = [
        'trade_date' => 'date',
        'realized' => 'decimal:2',
        'unrealized' => 'decimal:2',
        'gross_pnl' => 'decimal:2',
        'costs' => 'decimal:2',
        'net_pnl' => 'decimal:2',
        'charges' => 'decimal:2',
        'trades_count' => 'integer',
        'wins' => 'integer',
        'losses' => 'integer',
        'target_progress' => 'decimal:2',
    ];

    /**
     * @return BelongsTo<TradingAccount, $this>
     */
    public function tradingAccount(): BelongsTo
    {
        return $this->belongsTo(TradingAccount::class);
    }
}
