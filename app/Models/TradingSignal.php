<?php

namespace App\Models;

use Database\Factories\TradingSignalFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 */
class TradingSignal extends Model
{
    /** @use HasFactory<TradingSignalFactory> */
    use HasFactory;

    use HasUuids;

    protected $fillable = [
        'stock_id', 'trading_account_id', 'signal_date', 'signal_time', 'price',
        'score', 'decline_score', 'reversal_score', 'volume_score',
        'technical_score', 'liquidity_score', 'volatility_score',
        'proposed_sl', 'proposed_target1', 'proposed_target2', 'proposed_target3',
        'proposed_quantity', 'risk_per_share', 'reward_per_share', 'decline_percent',
        'risk_reward_ratio', 'entry_reasons', 'indicators_at_entry',
        'market_condition', 'status', 'rejection_reason',
    ];

    protected $casts = [
        'signal_date' => 'date',
        'signal_time' => 'datetime',
        'price' => 'decimal:4',
        'score' => 'float',
        'decline_score' => 'float',
        'reversal_score' => 'float',
        'volume_score' => 'float',
        'technical_score' => 'float',
        'liquidity_score' => 'float',
        'volatility_score' => 'float',
        'proposed_sl' => 'decimal:4',
        'proposed_target1' => 'decimal:4',
        'proposed_target2' => 'decimal:4',
        'proposed_target3' => 'decimal:4',
        'proposed_quantity' => 'decimal:0',
        'risk_per_share' => 'decimal:4',
        'reward_per_share' => 'decimal:4',
        'risk_reward_ratio' => 'float',
        'decline_percent' => 'float',
        'entry_reasons' => 'json',
        'indicators_at_entry' => 'json',
    ];

    /**
     * @return BelongsTo<Stock, $this>
     */
    public function stock(): BelongsTo
    {
        return $this->belongsTo(Stock::class);
    }

    /**
     * @return BelongsTo<TradingAccount, $this>
     */
    public function tradingAccount(): BelongsTo
    {
        return $this->belongsTo(TradingAccount::class);
    }
}
