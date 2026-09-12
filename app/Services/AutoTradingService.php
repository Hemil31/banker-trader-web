<?php

namespace App\Services;

use App\Models\Stock;
use App\Models\SystemEvent;
use App\Models\TradingAccount;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Automated trading loop. Runs the full session (news fetch → signal scan →
 * enter → monitor exits → reconcile) for every enrolled user account, each
 * with its own broker connection and its own config scaled by the account's
 * capital (per-account overrides, including the 2–3% daily profit target).
 *
 * Only accounts with master_enabled + strategy_enabled participate. Ac­counts
 * with a live broker but no credentials are skipped and logged. Open-position
 * monitoring continues even after the daily target halts new entries, so a
 * reached target locks in the day's gains without stranding running trades.
 */
class AutoTradingService
{
    public function __construct(
        protected PaperTradingService $paper,
        protected TradingConfigService $config,
        protected RiskManager $risk,
    ) {}

    /**
     * Run automation for every enrolled account (or a single account).
     *
     * @param  Collection<int, Stock>  $stocks  watchlist (defaults to all active)
     * @return array<string, array<string, mixed>> keyed by trading account id
     */
    public function run(Collection $stocks, ?string $accountId = null): array
    {
        $accounts = $this->enabledAccounts($accountId);

        if ($stocks->isEmpty()) {
            $stocks = Stock::where('active', true)->where('in_watchlist', true)->get();
        }

        if ($accounts->isEmpty()) {
            return [];
        }

        $results = [];

        foreach ($accounts as $account) {
            // Safety: never fall back to the paper broker for a live account
            // that lost its connection — skip and surface it instead.
            if ($account->mode === 'live' && ! $account->isLiveBrokerConnected()) {
                $results[$account->id] = [
                    'account' => $account,
                    'error' => 'live account has no connected broker — skipped',
                ];

                Log::warning("Automation skipped live account {$account->id}: broker not connected.");

                continue;
            }

            try {
                $results[$account->id] = $this->runForAccount($account, $stocks);
            } catch (\Throwable $e) {
                Log::error("Automation failed for account {$account->id}: {$e->getMessage()}", [
                    'exception' => $e,
                ]);

                SystemEvent::create([
                    'type' => 'automation',
                    'action' => 'failed',
                    'subject_type' => TradingAccount::class,
                    'subject_id' => $account->id,
                    'description' => "Automation run failed: {$e->getMessage()}",
                ]);

                $results[$account->id] = [
                    'account' => $account,
                    'error' => $e->getMessage(),
                ];
            }
        }

        // Leave the shared config on the global defaults for any later call.
        $this->config->clearOverrides();

        return $results;
    }

    /**
     * Run the session for one account with its config overrides applied.
     *
     * @param  Collection<int, Stock>  $stocks
     * @return array<string, mixed>
     */
    public function runForAccount(TradingAccount $account, Collection $stocks): array
    {
        $this->config->clearOverrides();
        $this->config->useOverrides($account->configOverrides());

        $session = $this->paper->runForAccount(
            $account->loadMissing(['broker', 'user']),
            $stocks,
        );

        $risk = $this->risk->metrics($account->id);
        $overrides = $account->configOverrides();
        $dailyTarget = (float) ($overrides['risk.daily_target'] ?? 0);

        SystemEvent::create([
            'type' => 'automation',
            'action' => 'run_completed',
            'subject_type' => TradingAccount::class,
            'subject_id' => $account->id,
            'description' => sprintf(
                'Automation run: %d signals, %d entered, %d exits, daily realized ₹%s',
                $session['signals_generated'],
                $session['entered'],
                $session['exits'],
                number_format((float) $risk['realized'], 2),
            ),
        ]);

        return [
            'account' => $account,
            'mode' => $account->mode,
            'broker' => $account->broker->slug ?? 'paper',
            'capital' => $account->effectiveCapital(),
            'daily_target' => round($dailyTarget, 2),
            'signals_generated' => $session['signals_generated'],
            'market_ok' => $session['market_ok'],
            'entered' => $session['entered'],
            'blocked' => $session['blocked'],
            'monitored' => $session['monitored'],
            'exits' => $session['exits'],
            'daily_realized' => (float) $risk['realized'],
            'daily_target_met' => $dailyTarget > 0 && $risk['realized'] >= $dailyTarget,
            'open_positions' => (int) $risk['open_positions'],
            'portfolio' => $session['portfolio'],
            'error' => null,
        ];
    }

    /**
     * Enrolled accounts — every account both master- and strategy-enabled.
     *
     * @return Collection<int, TradingAccount>
     */
    public function enabledAccounts(?string $accountId = null): Collection
    {
        return TradingAccount::query()
            ->with(['broker', 'user'])
            ->when($accountId !== null && $accountId !== '', fn ($q) => $q->whereKey($accountId))
            ->where('master_enabled', true)
            ->where('strategy_enabled', true)
            ->orderBy('created_at')
            ->get()
            ->filter(fn (TradingAccount $account) => $account->isAutomationEnabled());
    }
}
