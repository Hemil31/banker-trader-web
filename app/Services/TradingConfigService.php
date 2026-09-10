<?php

namespace App\Services;

use App\Models\SystemEvent;
use App\Models\TradingConfig;

/**
 * Typed accessor for the strategy configuration store (trading_configs table),
 * with an optional in-memory cache. Values are never hard-coded in engine code.
 */
class TradingConfigService
{
    public function __construct(protected ?string $overridesKey = null)
    {
        $this->snapshot = null;
    }

    /** @var array<string, mixed>|null */
    protected ?array $snapshot;

    /**
     * Serve reads from an in-memory snapshot instead of the DB. Values are
     * immutable during a single execution, so this removes per-read queries.
     *
     * @param  array<string, mixed>  $snapshot
     */
    public function useSnapshot(array $snapshot): self
    {
        $this->snapshot = $snapshot;

        return $this;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        if ($this->snapshot !== null) {
            return array_key_exists($key, $this->snapshot) ? $this->snapshot[$key] : $default;
        }

        return TradingConfig::get($key, $default);
    }

    public function float(string $key, float $default = 0.0): float
    {
        return (float) $this->get($key, $default);
    }

    public function int(string $key, int $default = 0): int
    {
        return (int) $this->get($key, $default);
    }

    public function bool(string $key, bool $default = false): bool
    {
        return (bool) $this->get($key, $default);
    }

    /**
     * @param  array<mixed>  $default
     * @return array<mixed>
     */
    public function array(string $key, array $default = []): array
    {
        return TradingConfig::getArray($key, $default);
    }

    /**
     * Persist a config change and record an audit event.
     */
    public function set(string $key, mixed $value, ?string $actor = 'admin'): void
    {
        $before = TradingConfig::get($key, null);
        TradingConfig::set($key, $value);

        if ($this->snapshot !== null) {
            $this->snapshot[$key] = $value;
        }

        SystemEvent::create([
            'type' => 'config',
            'action' => 'updated',
            'actor' => $actor,
            'description' => "Config updated: {$key} = ".(is_array($value) ? json_encode($value) : (string) $value),
            'data' => ['key' => $key, 'before' => $before, 'after' => $value],
        ]);
    }
}
