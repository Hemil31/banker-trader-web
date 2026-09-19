<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * One row per non-trading or special-session day for a given exchange (MIC),
 * synced from MarketCalendarService::syncHolidays(). Used by RiskManager to
 * block new entries on Indian market holidays.
 *
 * @property string $id
 */
class MarketHoliday extends Model
{
    use HasUuids;

    protected $table = 'market_holidays';

    protected $fillable = [
        'mic', 'exchange', 'date', 'day_of_week', 'is_weekend',
        'is_business_day', 'holiday_name', 'is_early_close',
        'open_time', 'close_time',
    ];

    protected $casts = [
        'date' => 'date',
        'is_weekend' => 'boolean',
        'is_business_day' => 'boolean',
        'is_early_close' => 'boolean',
    ];
}
