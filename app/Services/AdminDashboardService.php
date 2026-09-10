<?php

namespace App\Services;

use App\Models\Order;
use App\Models\PaperTrade;
use App\Models\Position;
use App\Models\TradingAccount;
use App\Models\TradingPnlLedger;
use App\Models\TradingSignal;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Company-level view over every user, account and trading outcome.
 * Powers the admin master table ("which user, what did they do, how much did they make/lose").
 */
class AdminDashboardService
{
    /**
     * Master table of all platform users.
     *
     * @return array<int, array<string, mixed>>
     */
    public function userMasterTable(): array
    {
        $users = User::query()
            ->with('tradingAccounts.broker')
            ->orderByDesc('created_at')
            ->get();

        $paper = PaperTrade::query()->where('status', 'closed')
            ->selectRaw('trading_account_id, SUM(pnl_net) as total')
            ->groupBy('trading_account_id')->pluck('total', 'trading_account_id');

        $ledger = TradingPnlLedger::query()
            ->selectRaw('trading_account_id, SUM(net) as total')
            ->groupBy('trading_account_id')->pluck('total', 'trading_account_id');

        $open = Position::query()->where('status', 'open')
            ->selectRaw('trading_account_id, COUNT(*) as count, SUM(unrealized_pnl_net) as unrealized')
            ->groupBy('trading_account_id')
            ->get()->keyBy('trading_account_id');

        $orders = Order::query()
            ->selectRaw('trading_account_id, COUNT(*) as count')
            ->groupBy('trading_account_id')->pluck('count', 'trading_account_id');

        $signals = TradingSignal::query()
            ->selectRaw('trading_account_id, COUNT(*) as count')
            ->whereNotNull('trading_account_id')
            ->groupBy('trading_account_id')->pluck('count', 'trading_account_id');

        return $users->map(function (User $user) use ($paper, $ledger, $open, $orders, $signals): array {
            $accounts = $user->tradingAccounts
                ->map(fn (TradingAccount $account) => $this->accountRow($account, $paper, $ledger, $open, $orders, $signals))
                ->values()
                ->all();

            return [
                'id' => $user->id,
                'name' => $user->name,
                'username' => $user->username,
                'email' => $user->email,
                'is_admin' => $user->is_admin,
                'email_verified_at' => $user->email_verified_at?->toIso8601String(),
                'last_login' => $user->last_login?->toIso8601String(),
                'created_at' => $user->created_at?->toIso8601String(),
                'accounts' => $accounts,
                'totals' => [
                    'accounts' => count($accounts),
                    'realized_pnl_net' => $this->sum($accounts, 'realized_pnl_net'),
                    'unrealized_pnl_net' => $this->sum($accounts, 'unrealized_pnl_net'),
                    'orders_count' => $this->sum($accounts, 'orders_count'),
                    'signals_count' => $this->sum($accounts, 'signals_count'),
                    'open_positions' => $this->sum($accounts, 'open_positions'),
                ],
            ];
        })->all();
    }

    /**
     * Single user detail incl. per-account breakdown and recent activity.
     *
     * @return array<string, mixed>
     */
    public function userDetail(User $user): array
    {
        $row = collect($this->userMasterTable())->firstWhere('id', $user->id);

        $recent = PaperTrade::query()
            ->whereIn('trading_account_id', $user->tradingAccounts()->pluck('id'))
            ->latest('executed_at')
            ->limit(25)
            ->get()
            ->map(fn (PaperTrade $trade): array => [
                'id' => $trade->id,
                'symbol' => $trade->symbol,
                'direction' => $trade->direction,
                'size' => (float) $trade->quantity,
                'entry' => (float) $trade->fill_price,
                'pnl_net' => (float) $trade->pnl_net,
                'status' => $trade->status,
                'executed_at' => $trade->executed_at?->toIso8601String(),
                'exited_at' => $trade->exited_at?->toIso8601String(),
            ])
            ->all();

        return [
            'user' => $row,
            'recent_paper_trades' => $recent,
        ];
    }

    /**
     * @param  Collection<int, mixed>  $paper
     * @param  Collection<int, mixed>  $ledger
     * @param  Collection<int, mixed>  $open
     * @param  Collection<int, mixed>  $orders
     * @param  Collection<int, mixed>  $signals
     * @return array<string, mixed>
     */
    protected function accountRow(
        TradingAccount $account,
        $paper,
        $ledger,
        $open,
        $orders,
        $signals,
    ): array {
        $openRow = $open->get($account->id);

        return [
            'id' => $account->id,
            'name' => $account->name,
            'mode' => $account->mode,
            'broker' => $account->broker?->name,
            'broker_connected' => $account->isLiveBrokerConnected(),
            'starting_capital' => (float) $account->starting_capital,
            'available_cash' => (float) $account->available_cash,
            'invested_amount' => (float) $account->invested_amount,
            'master_enabled' => (bool) $account->master_enabled,
            'strategy_enabled' => (bool) $account->strategy_enabled,
            'realized_pnl_net' => round((float) ($paper->get($account->id, 0) + $ledger->get($account->id, 0)), 2),
            'unrealized_pnl_net' => round((float) ($openRow->unrealized ?? 0), 2),
            'open_positions' => (int) ($openRow->count ?? 0),
            'orders_count' => (int) $orders->get($account->id, 0),
            'signals_count' => (int) $signals->get($account->id, 0),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    protected function sum(array $rows, string $key): float|int
    {
        return array_sum(array_column($rows, $key));
    }
}
