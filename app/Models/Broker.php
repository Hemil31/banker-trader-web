<?php

namespace App\Models;

use Database\Factories\BrokerFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 */
class Broker extends Model
{
    /** @use HasFactory<BrokerFactory> */
    use HasFactory;

    use HasUuids;

    protected $fillable = [
        'slug', 'name', 'paper', 'active', 'credentials',
        'api_status', 'api_status_message', 'last_checked_at',
    ];

    protected $casts = [
        'paper' => 'boolean',
        'active' => 'boolean',
        'credentials' => 'json',
        'last_checked_at' => 'datetime',
    ];

    public function isActive(): bool
    {
        return (bool) $this->active;
    }

    /**
     * Scope to active brokers.
     *
     * @param  Builder<Broker>  $query
     * @return Builder<Broker>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', true);
    }
}
