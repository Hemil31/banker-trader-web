<?php

namespace App\Models;

use Database\Factories\IpoFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One initial public offering tracked by the IPO module (upserted by slug from
 * the IPO Ji provider, synced alongside its day-wise subscription and GMP
 * history). `current_subscription` / `current_gmp` / `expected_premium[_pct]`
 * are denormalized card-level values refreshed at ingest time.
 *
 * @property string $id
 */
class Ipo extends Model
{
    /** @use HasFactory<IpoFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'ipos';

    protected $fillable = [
        'slug', 'name', 'symbol', 'board', 'status',
        'price_min', 'price_max', 'lot_size',
        'issue_size_cr', 'issue_size_text', 'total_issue_shares', 'ofs_shares',
        'open_date', 'close_date', 'allotment_date', 'listing_date', 'listing_at',
        'face_value', 'registrar', 'lead_managers', 'reservation', 'issue_objectives',
        'about', 'promoters', 'pre_issue_holding', 'post_issue_holding',
        'financials', 'peers', 'strengths', 'risks', 'contact_details',
        'logo_url', 'source_url',
        'current_subscription', 'current_gmp', 'expected_premium', 'expected_premium_pct',
        'raw_payload', 'synced_at',
    ];

    protected $casts = [
        'price_min' => 'decimal:2',
        'price_max' => 'decimal:2',
        'issue_size_cr' => 'decimal:2',
        'open_date' => 'date',
        'close_date' => 'date',
        'allotment_date' => 'date',
        'listing_date' => 'date',
        'lead_managers' => 'array',
        'reservation' => 'array',
        'financials' => 'array',
        'peers' => 'array',
        'strengths' => 'array',
        'risks' => 'array',
        'contact_details' => 'array',
        'current_subscription' => 'float',
        'current_gmp' => 'float',
        'expected_premium' => 'float',
        'expected_premium_pct' => 'float',
        'raw_payload' => 'array',
        'synced_at' => 'datetime',
    ];

    /**
     * @return HasMany<IpoSubscriptionSnapshot, $this>
     */
    public function subscriptionSnapshots(): HasMany
    {
        return $this->hasMany(IpoSubscriptionSnapshot::class);
    }

    /**
     * @return HasMany<IpoGmpHistory, $this>
     */
    public function gmpHistory(): HasMany
    {
        return $this->hasMany(IpoGmpHistory::class);
    }

    /**
     * @return HasMany<IpoApplication, $this>
     */
    public function applications(): HasMany
    {
        return $this->hasMany(IpoApplication::class);
    }

    /**
     * @return HasMany<IpoAllotment, $this>
     */
    public function allotments(): HasMany
    {
        return $this->hasMany(IpoAllotment::class);
    }
}
