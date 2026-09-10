<?php

namespace App\Models;

use Database\Factories\TradingPnlLedgerFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 */
class TradingPnlLedger extends Model
{
    /** @use HasFactory<TradingPnlLedgerFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'trading_pnl_ledger';

    protected $fillable = [
        'trading_account_id', 'position_id', 'gross', 'brokerage', 'stt',
        'exchange_charges', 'gst', 'sebi', 'stamp_duty', 'slippage_cost',
        'total_costs', 'net', 'direction',
    ];

    protected $casts = [
        'gross' => 'decimal:2',
        'brokerage' => 'decimal:2',
        'stt' => 'decimal:2',
        'exchange_charges' => 'decimal:2',
        'gst' => 'decimal:2',
        'sebi' => 'decimal:2',
        'stamp_duty' => 'decimal:2',
        'slippage_cost' => 'decimal:2',
        'total_costs' => 'decimal:2',
        'net' => 'decimal:2',
    ];

    /**
     * @return BelongsTo<TradingAccount, $this>
     */
    public function tradingAccount(): BelongsTo
    {
        return $this->belongsTo(TradingAccount::class);
    }

    /**
     * @return BelongsTo<Position, $this>
     */
    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }
}
