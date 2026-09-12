<?php

namespace App\Services;

use App\Models\MarketData;
use App\Models\PaperTrade;
use App\Models\Position;
use App\Models\Stock;
use App\Models\TradingAccount;
use App\Models\TradingConfig;
use App\Models\TradingSignal;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Read-model + actions shared by the web dashboard and the mobile API.
 * Controllers stay thin and delegate every query/action here.
 */
class TradingDashboardService
{
    public function __construct(
        protected PortfolioManager $portfolio,
        protected PaperTradingService $paperTrading,
        protected TradingConfigService $config,
        protected MarketDataService $marketData,
    ) {}

    public function account(?User $user = null): TradingAccount
    {
        return $this->paperTrading->paperAccount($user);
    }

    /**
     * Everything the landing dashboard needs in one payload.
     *
     * @return array<string, mixed>
     */
    public function overview(?User $user = null): array
    {
        $account = $this->account($user);
        $portfolio = $this->portfolio->summary($account->id);

        $open = $this->portfolio->openPositions($account->id)
            ->map(fn (Position $p) => $this->positionRow($p))
            ->values()
            ->all();

        return [
            'account' => [
                'id' => $account->id,
                'name' => $account->name,
                'mode' => $account->mode,
                'starting_capital' => (float) $account->starting_capital,
                'available_cash' => (float) $account->available_cash,
                'invested_amount' => (float) $account->invested_amount,
                'started_at' => $account->started_at?->toIso8601String(),
            ],
            'portfolio' => $portfolio,
            'open_positions' => $open,
            'recent_signals' => $this->signals(8),
            'recent_paper_trades' => $this->paperTrades(5, $user),
            'market_bars' => MarketData::count(),
            'config_count' => TradingConfig::count(),
        ];
    }

    /**
     * Recent candidate signals with their stock.
     *
     * @return array<int, array<string, mixed>>
     */
    public function signals(int $limit = 50): array
    {
        return TradingSignal::with('stock')
            ->latest('signal_date')
            ->limit($limit)
            ->get()
            ->map(fn (TradingSignal $signal) => [
                'id' => $signal->id,
                'symbol' => $signal->stock?->symbol,
                'signal_date' => $signal->signal_date->toIso8601String(),
                'price' => (float) ($signal->price ?? 0),
                'score' => (float) $signal->score,
                'proposed_sl' => (float) ($signal->proposed_sl ?? 0),
                'proposed_target1' => (float) ($signal->proposed_target1 ?? 0),
                'proposed_target3' => (float) ($signal->proposed_target3 ?? 0),
                'risk_reward_ratio' => (float) ($signal->risk_reward_ratio ?? 0),
                'entry_reasons' => $signal->entry_reasons,
                'status' => $signal->status,
            ])
            ->all();
    }

    /**
     * Positions (open and/or closed) for the user's own paper account.
     *
     * @return array<int, array<string, mixed>>
     */
    public function positions(?string $status = null, int $limit = 100, ?User $user = null): array
    {
        $account = $this->account($user);

        return Position::with('stock')
            ->where('trading_account_id', $account->id)
            ->when($status !== null, fn ($query) => $query->where('status', $status))
            ->latest('opened_at')
            ->limit($limit)
            ->get()
            ->map(fn (Position $p) => $this->positionRow($p))
            ->all();
    }

    /**
     * Lane-by-lane paper trade ledger rows for the user's own account.
     *
     * @return array<int, array<string, mixed>>
     */
    public function paperTrades(int $limit = 50, ?User $user = null): array
    {
        $account = $this->account($user);

        return PaperTrade::where('trading_account_id', $account->id)
            ->latest('id')
            ->limit($limit)
            ->get()
            ->map(fn (PaperTrade $trade) => [
                'id' => $trade->id,
                'position_id' => $trade->position_id,
                'symbol' => $trade->symbol,
                'direction' => $trade->direction,
                'signal_price' => (float) ($trade->signal_price ?? 0),
                'fill_price' => (float) ($trade->fill_price ?? 0),
                'quantity' => (float) ($trade->quantity ?? 0),
                'stop_loss' => (float) ($trade->stop_loss ?? 0),
                'target' => (float) ($trade->target ?? 0),
                'pnl' => (float) ($trade->pnl ?? 0),
                'pnl_net' => (float) ($trade->pnl_net ?? 0),
                'status' => $trade->status,
                'entry_reason' => $trade->entry_reason,
                'exit_reason' => $trade->exit_reason,
                'executed_at' => $trade->executed_at?->toIso8601String(),
                'exited_at' => $trade->exited_at?->toIso8601String(),
            ])
            ->all();
    }

    /**
     * Config rows (optionally only editable ones) ordered by group/key.
     *
     * @return array<int, array<string, mixed>>
     */
    public function configRows(bool $editableOnly = false): array
    {
        return TradingConfig::query()
            ->when($editableOnly, fn ($query) => $query->where('is_editable', true))
            ->orderBy('group')
            ->orderBy('key')
            ->get()
            ->map(fn (TradingConfig $row) => [
                'key' => $row->key,
                'group' => $row->group,
                'type' => $row->type,
                'value' => TradingConfig::castValue($row->type, $row->value),
                'label' => $row->label,
                'is_editable' => (bool) $row->is_editable,
            ])
            ->all();
    }

    public function updateConfig(string $key, mixed $value, string $actor = 'admin'): void
    {
        $this->config->set($key, $value, $actor);
    }

    /**
     * Trigger one paper session over the watchlist (or specific stock ids).
     *
     * @param  array<int, int>|null  $symbolIds
     * @return array{account: TradingAccount, signals_generated: int, market_ok: bool, entered: int, blocked: int, monitored: int, exits: int, portfolio: array<string, float>}
     */
    public function runSession(?array $symbolIds = null, ?User $user = null): array
    {
        $stocks = $this->stocksFor($symbolIds);

        return $this->paperTrading->runSession($stocks, null, $user);
    }

    /**
     * @param  array<int, int>|null  $symbolIds
     * @return Collection<int, Stock>
     */
    protected function stocksFor(?array $symbolIds): Collection
    {
        if ($symbolIds !== null && $symbolIds !== []) {
            return Stock::whereIn('id', $symbolIds)->where('active', true)->get();
        }

        return Stock::where('active', true)->where('in_watchlist', true)->get();
    }

    /**
     * @return array<string, mixed>
     */
    protected function positionRow(Position $p): array
    {
        return [
            'id' => $p->id,
            'symbol' => $p->stock?->symbol,
            'status' => $p->status,
            'quantity' => (float) $p->quantity,
            'partial_booked_qty' => (float) ($p->partial_booked_qty ?? 0),
            'avg_entry_price' => (float) ($p->avg_entry_price ?? 0),
            'entry_value' => (float) ($p->entry_value ?? 0),
            'stop_loss' => (float) ($p->stop_loss ?? 0),
            'target1' => (float) ($p->target1 ?? 0),
            'target2' => (float) ($p->target2 ?? 0),
            'target3' => (float) ($p->target3 ?? 0),
            'current_stop' => (float) ($p->current_stop ?? 0),
            'unrealized_pnl' => (float) ($p->unrealized_pnl ?? 0),
            'unrealized_pnl_net' => (float) ($p->unrealized_pnl_net ?? 0),
            'realized_pnl' => (float) ($p->realized_pnl ?? 0),
            'realized_pnl_net' => (float) ($p->realized_pnl_net ?? 0),
            'net_pnl' => (float) ($p->net_pnl ?? 0),
            'close_reason' => $p->close_reason,
            'exit_price' => (float) ($p->exit_price ?? 0),
            'opened_at' => $p->opened_at?->toIso8601String(),
            'closed_at' => $p->closed_at?->toIso8601String(),
        ];
    }
}
