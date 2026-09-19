<?php

namespace App\Models;

use Database\Factories\TradingConfigFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Strategy configuration store. Values are never hard-coded in code — read them
 * through this model / the TradingConfigService.
 */
/**
 * @property string $id
 */
class TradingConfig extends Model
{
    /** @use HasFactory<TradingConfigFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'trading_configs';

    protected $fillable = [
        'key', 'group', 'type', 'value', 'label', 'description', 'is_editable',
    ];

    protected $casts = [
        'is_editable' => 'boolean',
    ];

    public static function get(string $key, mixed $default = null): mixed
    {
        $row = static::where('key', $key)->first();
        if (! $row) {
            return $default;
        }

        return static::castValue($row->type, $row->value);
    }

    /**
     * Cast a stored string value by its registered type.
     */
    public static function castValue(string $type, mixed $value): mixed
    {
        return match ($type) {
            'boolean' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            'integer' => (int) $value,
            'float' => (float) $value,
            'array', 'json' => json_decode((string) $value, true),
            default => $value,
        };
    }

    /**
     * Load every config key once, typed, ready for an in-memory snapshot
     * (avoids per-key queries on hot paths like backtests).
     *
     * @return array<string, mixed>
     */
    public static function allTyped(): array
    {
        $out = [];
        foreach (static::all() as $row) {
            $out[$row->key] = static::castValue($row->type, $row->value);
        }

        return $out;
    }

    /**
     * @param  array<mixed>  $default
     * @return array<mixed>
     */
    public static function getArray(string $key, array $default = []): array
    {
        $value = static::get($key, $default);

        return is_array($value) ? $value : (array) $value;
    }

    /**
     * @param  array<mixed>  $meta
     */
    public static function set(string $key, mixed $value, array $meta = []): self
    {
        $type = match (true) {
            is_bool($value) => 'boolean',
            is_int($value) => 'integer',
            is_float($value) => 'float',
            is_array($value) => 'array',
            default => 'string',
        };

        $stored = is_array($value) ? json_encode($value) : (is_bool($value) ? ($value ? '1' : '0') : (string) $value);

        return static::updateOrCreate(
            ['key' => $key],
            array_merge($meta, ['key' => $key, 'type' => $type, 'value' => $stored]),
        );
    }

    /**
     * Register a config key if it does not exist yet. Used to seed defaults.
     */
    public static function registerDefault(
        string $key,
        mixed $default,
        string $group = 'general',
        string $type = 'float',
        ?string $label = null,
        ?string $description = null,
        bool $editable = true,
    ): void {
        if (static::where('key', $key)->exists()) {
            return;
        }

        $value = match ($type) {
            'boolean' => $default ? '1' : '0',
            'array' => json_encode($default),
            default => (string) $default,
        };

        static::create([
            'key' => $key,
            'group' => $group,
            'type' => $type,
            'value' => $value,
            'label' => $label ?? $key,
            'description' => $description,
            'is_editable' => $editable,
        ]);
    }

    public static function pluckDefaults(): void
    {
        $defaults = [
            // group, key, type, label, description, value
            ['risk', 'risk.capital', 'float', 'Starting capital (₹)', 'Trading capital used for position sizing', 100000],
            ['risk', 'risk.amount_per_trade', 'float', 'Risk per trade (₹)', 'Maximum rupee risk per trade (risk-based sizing)', 200],
            ['risk', 'risk.stop_loss_pct', 'float', 'Initial stop-loss %', 'Stop-loss as % below entry', 2],
            ['risk', 'risk.target1_pct', 'float', 'Target 1 %', 'First profit target (book half + trail to entry)', 3],
            ['risk', 'risk.target2_pct', 'float', 'Target 2 %', 'Second profit target', 4],
            ['risk', 'risk.target3_pct', 'float', 'Target 3 %', 'Final profit target (full exit)', 7],
            ['risk', 'risk.daily_target', 'float', 'Daily profit target (₹)', 'Stop opening new trades once reached', 500],
            ['risk', 'risk.daily_loss_cap', 'float', 'Daily max loss (₹)', 'Stop opening new trades once hit', 500],
            ['risk', 'risk.max_trades_per_day', 'integer', 'Max new trades/day', 'Maximum new trades opened per day', 5],
            ['risk', 'risk.max_open_positions', 'integer', 'Max open positions', 'Stop opening new trades once this many positions are open (0 = no cap)', 10],
            ['risk', 'risk.max_data_staleness_days', 'integer', 'Max data staleness (days)', 'Skip scanning/monitoring a stock whose latest stored bar is older than this many days', 4],
            ['risk', 'risk.trailing_enabled', 'boolean', 'Trailing stop enabled', 'Trail stop up once a position is profitable', false],
            ['risk', 'risk.trailing_pct', 'float', 'Trailing stop %', 'Trailing stop distance below the high', 1.5],
            ['position', 'position.max_pct_per_stock', 'float', 'Max per-stock allocation %', 'Maximum % of capital in a single stock', 20],
            ['position', 'position.max_exposure_pct', 'float', 'Max total exposure %', 'Maximum % of capital invested at once', 70],
            ['product', 'product.min_price', 'float', 'Min price (₹)', 'Skip stocks trading below this price', 20],
            ['product', 'product.min_score', 'float', 'Minimum signal score', 'Only trade signals above this composite score', 60],
            ['product', 'product.min_reversal_confirmations', 'integer', 'Required confirmations', 'Minimum reversal confirmations (hard gate)', 2],
            ['product', 'product.min_avg_volume', 'float', 'Min avg daily volume', 'Minimum 20-day average volume (liquidity screen)', 50000],
            ['product', 'product.weights.decline', 'float', 'Decline weight', 'Composite score weight', 0.15],
            ['product', 'product.weights.reversal', 'float', 'Reversal weight', 'Composite score weight', 0.20],
            ['product', 'product.weights.volume', 'float', 'Volume weight', 'Composite score weight', 0.15],
            ['product', 'product.weights.technical', 'float', 'Technical weight', 'Composite score weight', 0.15],
            ['product', 'product.weights.liquidity', 'float', 'Liquidity weight', 'Composite score weight', 0.10],
            ['product', 'product.weights.volatility', 'float', 'Volatility weight', 'Composite score weight', 0.10],
            ['product', 'product.weights.news', 'float', 'News weight', 'Composite score weight (news sentiment component)', 0.15],
            ['news', 'news.enabled', 'boolean', 'News sentiment enabled', 'Include news sentiment in the signal score', true],
            ['news', 'news.lookback_hours', 'integer', 'News lookback (hours)', 'How far back to search for headlines', 48],
            ['news', 'news.results', 'integer', 'News results per stock', 'Maximum headlines fetched per stock', 10],
            ['news', 'news.daily_cap', 'integer', 'News daily cap', 'Max FreeNewsApi requests per day (free tier 5000)', 1000],
            ['costs', 'costs.one_side_rate_pct', 'float', 'One-side cost %', 'Brokerage + impact estimate per side', 0.3],
            ['broker', 'broker.default_slug', 'string', 'Default broker', 'Active broker slug (paper | zerodha | upstox)', 'paper'],
            ['backtest', 'backtest.slippage_bps', 'float', 'Slippage (bps)', 'Assumed slippage in basis points for backtests', 5],
            ['market', 'market.filter_enabled', 'boolean', 'Market filter enabled', 'Apply overall market condition filter', false],
            ['market', 'market.filter', 'string', 'Market filter mode', 'none | nifty_sensex | custom', 'none'],
            ['market', 'market.min_index_change', 'float', 'Min index change %', 'Minimum index move for the filter to pass', -0.5],
            ['market', 'market.custom_index_change', 'float', 'Custom index change %', 'Custom filter index change', 0],
        ];

        foreach ($defaults as [$group, $key, $type, $label, $desc, $value]) {
            static::registerDefault($key, $value, $group, $type, $label, $desc);
        }

        // A secret, not a per-account tuning knob: kept out of the editable
        // set so it never surfaces on GET /api/trading/config or accepts a
        // PATCH from the per-user mobile Config tab. Set it directly in the
        // DB (or via TradingConfigService::set() from a trusted context).
        static::registerDefault(
            'news.api_key',
            '',
            'news',
            'string',
            'News API key',
            'FreeNewsApi.io key sent as the x-api-key header. DB-backed with an env fallback (NEWS_API_KEY) — see FreeNewsApiProvider.',
            editable: false,
        );

        static::registerDefault(
            'zernio.api_key',
            '',
            'zernio',
            'string',
            'Zernio API key',
            'Zernio API key sent as the Authorization: Bearer header. DB-backed with an env fallback (ZERNIO_API_KEY) — see SdkZernioClient.',
            editable: false,
        );

        // Timezone used when interpreting the admin-entered "scheduled at"
        // datetime for Zernio posts. Company-wide (all admins share the same
        // posting schedule), so it stays off the per-user config API.
        static::registerDefault(
            'zernio.timezone',
            'Asia/Kolkata',
            'zernio',
            'string',
            'Timezone',
            'Timezone for Zernio scheduled posts, e.g. Asia/Kolkata.',
            editable: false,
        );

        // Gemini API key, DB-backed with an env fallback (GEMINI_API_KEY) —
        // see HttpGeminiClient. Same "not editable via the generic PATCH"
        // treatment as news.api_key / zernio.api_key.
        static::registerDefault(
            'gemini.api_key',
            '',
            'gemini',
            'string',
            'Gemini API key',
            'Google Gemini API key sent as the X-goog-api-key header. DB-backed with an env fallback (GEMINI_API_KEY) — see HttpGeminiClient.',
            editable: false,
        );

        static::registerDefault(
            'gemini.model',
            (string) config('gemini.model', 'gemini-flash-lite-latest'),
            'gemini',
            'string',
            'Gemini model',
            'Model id used for AI post generation, e.g. gemini-flash-lite-latest. Must match a model actually enabled in the Gemini API console.',
        );

        static::registerDefault(
            'gemini.rpm',
            (int) config('gemini.rpm', 10),
            'gemini',
            'integer',
            'Gemini requests/minute',
            'Max Gemini calls per minute for AI post generation — must match the configured model\'s published RPM quota. Enforced by the gemini-generation queue rate limiter.',
        );

        static::registerDefault(
            'gemini.rpd',
            (int) config('gemini.rpd', 20),
            'gemini',
            'integer',
            'Gemini requests/day',
            'Max Gemini calls per day for AI post generation — must match the configured model\'s published RPD quota.',
        );

        static::registerDefault(
            'gemini.image_model',
            (string) config('gemini.image_model', 'gemini-2.5-flash-image'),
            'gemini',
            'string',
            'Gemini image model',
            'Image-generation model used to render a fresh, real post image per post (must support image output), e.g. gemini-2.5-flash-image.',
        );

        // Brand/business context fed into every Gemini post-generation
        // prompt (see GeminiPostGenerationService::buildPrompt). Editable
        // like the risk/product config — no code change needed to update
        // brand voice or audience.
        static::registerDefault(
            'content.brand_name',
            'BankerTrader',
            'content',
            'string',
            'Brand name',
            'Brand/business name used in AI-generated social posts.',
        );

        static::registerDefault(
            'content.business_description',
            '',
            'content',
            'string',
            'Business description',
            'Short description of the business, fed into the AI post-generation prompt for context.',
        );

        static::registerDefault(
            'content.target_audience',
            'retail traders and investors',
            'content',
            'string',
            'Target audience',
            'Who AI-generated posts should be written for.',
        );

        static::registerDefault(
            'content.tone',
            'friendly and professional',
            'content',
            'string',
            'Content tone',
            'Desired tone for AI-generated post captions.',
        );

        static::registerDefault(
            'content.default_hashtags',
            [],
            'content',
            'array',
            'Default hashtags',
            'Hashtags to include in AI-generated posts when relevant (e.g. the brand tag).',
        );

        // Emergency kill switch. Not editable via the generic PATCH endpoint —
        // only EmergencyControlService (and reconciliation, on a mismatch) may
        // flip these, so there is one clean, auditable path to halting the
        // platform rather than it being reachable as a side effect of a
        // routine config edit.
        static::registerDefault(
            'system.trading_halted',
            false,
            'system',
            'boolean',
            'Trading halted',
            'When true, RiskManager blocks all new order entries platform-wide. Open positions still exit normally. Set via EmergencyControlService, not this endpoint.',
            editable: false,
        );
        static::registerDefault(
            'system.halt_reason',
            '',
            'system',
            'string',
            'Halt reason',
            'Why system.trading_halted was set — surfaced as the RiskManager halt reason (e.g. manual_halt, reconciliation_mismatch).',
            editable: false,
        );
    }
}
