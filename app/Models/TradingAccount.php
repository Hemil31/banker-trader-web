<?php

namespace App\Models;

use Database\Factories\TradingAccountFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 */
class TradingAccount extends Model
{
    /** @use HasFactory<TradingAccountFactory> */
    use HasFactory;

    use HasUuids;

    protected $fillable = [
        'user_id', 'broker_id', 'name', 'starting_capital', 'available_cash',
        'invested_amount', 'mode', 'master_enabled', 'strategy_enabled',
        'started_at', 'credentials', 'algo_name',
    ];

    protected $casts = [
        'starting_capital' => 'decimal:2',
        'available_cash' => 'decimal:2',
        'invested_amount' => 'decimal:2',
        'master_enabled' => 'boolean',
        'strategy_enabled' => 'boolean',
        'started_at' => 'datetime',
        'credentials' => 'encrypted:json',
    ];

    /**
     * @return HasMany<Position, $this>
     */
    public function positions(): HasMany
    {
        return $this->hasMany(Position::class);
    }

    /**
     * @return HasMany<Order, $this>
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Broker, $this>
     */
    public function broker(): BelongsTo
    {
        return $this->belongsTo(Broker::class);
    }

    /**
     * Whether this account has a live broker connected.
     */
    public function isLiveBrokerConnected(): bool
    {
        return $this->broker_id !== null
            && $this->broker !== null
            && ! $this->broker->paper
            && is_array($this->credentials)
            && ! empty($this->credentials['access_token']);
    }

    /**
     * Get the access token for the connected broker.
     */
    public function getAccessToken(): ?string
    {
        return $this->credentials['access_token'] ?? null;
    }

    /**
     * Get a specific credential value.
     */
    public function getCredential(string $key, mixed $default = null): mixed
    {
        return $this->credentials[$key] ?? $default;
    }
}
