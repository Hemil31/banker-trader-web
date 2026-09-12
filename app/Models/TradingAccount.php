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
        'started_at', 'credentials', 'algo_name', 'settings',
    ];

    protected $casts = [
        'starting_capital' => 'decimal:2',
        'available_cash' => 'decimal:2',
        'invested_amount' => 'decimal:2',
        'master_enabled' => 'boolean',
        'strategy_enabled' => 'boolean',
        'started_at' => 'datetime',
        'credentials' => 'encrypted:json',
        'settings' => 'array',
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
     * Whether this account has a broker actually connected — the built-in
     * zero-config simulator (slug 'paper') never counts, but a credentialed
     * external provider does, whether or not it trades real money (e.g.
     * MegaBull is paper-flagged but still needs its own api-key connected).
     */
    public function isLiveBrokerConnected(): bool
    {
        return $this->broker_id !== null
            && $this->broker !== null
            && $this->broker->slug !== 'paper'
            && is_array($this->credentials)
            && (! empty($this->credentials['access_token']) || ! empty($this->credentials['api_key']));
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

    /**
     * Whether the account is enrolled in the automated trading loop:
     * master enable + strategy enable + optional explicit automation flag.
     */
    public function isAutomationEnabled(): bool
    {
        return $this->master_enabled
            && $this->strategy_enabled
            && ($this->settings['automation_enabled'] ?? true);
    }

    /**
     * Effective trading capital for sizing. Defaults to the account's
     * starting capital; the per-account settings may override it.
     */
    public function effectiveCapital(): float
    {
        return (float) ($this->settings['capital'] ?? $this->starting_capital ?? 100000);
    }

    /**
     * Strategy-config overrides for this account, merged over the global
     * trading_configs. An account can override any key; convenience *pct
     * settings are derived from the effective capital at read time.
     *
     * @return array<string, mixed>
     */
    public function configOverrides(): array
    {
        $capital = $this->effectiveCapital();
        $settings = is_array($this->settings) ? $this->settings : [];

        $overrides = ['risk.capital' => $capital];

        if (array_key_exists('daily_target_pct', $settings)) {
            $overrides['risk.daily_target'] = round($capital * (float) $settings['daily_target_pct'] / 100, 2);
        }

        if (array_key_exists('daily_loss_cap_pct', $settings)) {
            $overrides['risk.daily_loss_cap'] = round($capital * (float) $settings['daily_loss_cap_pct'] / 100, 2);
        }

        $passthrough = [
            'risk.amount_per_trade',
            'risk.stop_loss_pct',
            'risk.target1_pct',
            'risk.target2_pct',
            'risk.target3_pct',
            'risk.trailing_enabled',
            'risk.trailing_pct',
            'risk.max_trades_per_day',
            'position.max_pct_per_stock',
            'position.max_exposure_pct',
            'product.min_score',
        ];

        foreach ($passthrough as $key) {
            if (array_key_exists($key, $settings)) {
                $overrides[$key] = $settings[$key];
            }
        }

        return $overrides;
    }
}
