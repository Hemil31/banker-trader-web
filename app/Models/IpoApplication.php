<?php

namespace App\Models;

use Database\Factories\IpoApplicationFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A user's application (bid) for one IPO, tied to the PAN and demat account
 * used. `batch_id` groups a single bulk-apply submission. Status flows
 * draft → queued → submitted → allotment_pending → allotted | not_allotted.
 *
 * @property string $id
 */
class IpoApplication extends Model
{
    /** @use HasFactory<IpoApplicationFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'ipo_applications';

    protected $fillable = [
        'user_id', 'ipo_id', 'pan_card_id', 'demat_account_id', 'trading_account_id',
        'batch_id', 'lots', 'shares', 'amount', 'price_per_share',
        'application_number', 'status', 'failure_reason',
        'request_payload', 'response_payload', 'submitted_at', 'allotment_checked_at',
    ];

    protected $casts = [
        'lots' => 'integer',
        'shares' => 'integer',
        'amount' => 'decimal:2',
        'price_per_share' => 'decimal:2',
        'request_payload' => 'array',
        'response_payload' => 'array',
        'submitted_at' => 'datetime',
        'allotment_checked_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Ipo, $this>
     */
    public function ipo(): BelongsTo
    {
        return $this->belongsTo(Ipo::class);
    }

    /**
     * @return BelongsTo<PanCard, $this>
     */
    public function panCard(): BelongsTo
    {
        return $this->belongsTo(PanCard::class);
    }

    /**
     * @return BelongsTo<DematAccount, $this>
     */
    public function dematAccount(): BelongsTo
    {
        return $this->belongsTo(DematAccount::class);
    }

    /**
     * @return BelongsTo<TradingAccount, $this>
     */
    public function tradingAccount(): BelongsTo
    {
        return $this->belongsTo(TradingAccount::class);
    }

    /**
     * @return HasOne<IpoAllotment, $this>
     */
    public function allotment(): HasOne
    {
        return $this->hasOne(IpoAllotment::class);
    }
}
