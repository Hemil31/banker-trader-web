<?php

namespace App\Models;

use Database\Factories\OrderExecutionFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 */
class OrderExecution extends Model
{
    /** @use HasFactory<OrderExecutionFactory> */
    use HasFactory;

    use HasUuids;

    protected $fillable = [
        'order_id', 'stock_id', 'quantity', 'price',
        'brokerage', 'charges', 'executed_at',
    ];

    protected $casts = [
        'quantity' => 'decimal:0',
        'price' => 'decimal:4',
        'brokerage' => 'decimal:2',
        'charges' => 'decimal:2',
        'executed_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return BelongsTo<Stock, $this>
     */
    public function stock(): BelongsTo
    {
        return $this->belongsTo(Stock::class);
    }
}
