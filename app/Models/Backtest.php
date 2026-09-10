<?php

namespace App\Models;

use Database\Factories\BacktestFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 */
class Backtest extends Model
{
    /** @use HasFactory<BacktestFactory> */
    use HasFactory;

    use HasUuids;

    protected $fillable = [
        'name', 'start_date', 'end_date',
        'train_start', 'train_end', 'val_start', 'val_end',
        'oos_start', 'oos_end',
        'starting_capital', 'ending_capital', 'total_return_pct', 'cagr',
        'total_trades', 'wins', 'losses', 'win_rate', 'avg_profit', 'avg_loss',
        'profit_factor', 'max_drawdown', 'max_drawdown_pct',
        'max_consecutive_losses', 'largest_loss', 'largest_gain',
        'avg_holding_days', 'total_costs', 'net_profit',
        'buyhold_return_pct', 'buyhold_cagr',
        'monthly_pnl', 'daily_pnl', 'equity_curve', 'config_snapshot', 'meta',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'starting_capital' => 'decimal:2',
        'ending_capital' => 'decimal:2',
        'total_return_pct' => 'float',
        'cagr' => 'float',
        'win_rate' => 'float',
        'avg_profit' => 'decimal:2',
        'avg_loss' => 'decimal:2',
        'profit_factor' => 'float',
        'max_drawdown' => 'decimal:2',
        'max_drawdown_pct' => 'float',
        'largest_loss' => 'decimal:2',
        'largest_gain' => 'decimal:2',
        'avg_holding_days' => 'float',
        'total_costs' => 'decimal:2',
        'net_profit' => 'decimal:2',
        'buyhold_return_pct' => 'float',
        'buyhold_cagr' => 'float',
        'monthly_pnl' => 'json',
        'daily_pnl' => 'json',
        'equity_curve' => 'json',
        'config_snapshot' => 'json',
        'meta' => 'json',
    ];
}
