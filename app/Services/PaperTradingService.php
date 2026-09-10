<?php

namespace App\Services;

use App\Models\Order;
use App\Models\PaperTrade;
use App\Models\Position;
use App\Models\Stock;
use App\Models\SystemEvent;
use App\Models\TradingAccount;
use App\Models\TradingSignal;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Paper-trading session driver: scans the watchlist, opens positions through
 * the (simulated) paper broker, monitors SL/target exits, and mirrors every
 * round-trip into the paper_trades ledger so performance is reviewable
 * independently of the positional books.
 *
 * Positions, orders, realized P&L and daily aggregates are the source of truth
 * (ExecutionEngine). paper_trades is a human-readable mirror of each swing.
 */
class PaperTradingService
{
    public function __construct(
        protected SignalEngine $signals,
        protected ExecutionEngine $execution,
        protected PortfolioManager $portfolio,
        protected MarketDataService $marketData,
        protected TradingConfigService $config,
    ) {}

    /**
     * Get (or create) the single paper-trading account.
     */
    public function paperAccount(?User $user = null): TradingAccount
    {
        $account = TradingAccount::where('mode', 'paper')->first();
        if ($account) {
            return $account;
        }

        $capital = $this->config->float('risk.capital', 100000);

        $userId = $user !== null ? $user->id : User::query()->orderBy('id')->value('id');

        $account = TradingAccount::create([
            'user_id' => $userId,
            'name' => 'Paper Trading',
            'starting_capital' => $capital,
            'available_cash' => $capital,
            'invested_amount' => 0,
            'mode' => 'paper',
            'master_enabled' => true,
            'strategy_enabled' => true,
            'started_at' => now(),
        ]);

        SystemEvent::create([
            'type' => 'system',
            'action' => 'paper_account_created',
            'subject_type' => TradingAccount::class,
            'subject_id' => $account->id,
            'description' => "Paper-account created with ₹{$capital}",
        ]);

        return $account;
    }

    /**
     * Run one paper session:
     *   1. scan watchlist → persist candidate signals
     *   2. enter positions for new candidates (risk-approved)
     *   3. monitor existing open positions against latest prices
     *   4. reconcile the account cash balance
     *
     * @param  Collection<int, Stock>  $stocks
     * @return array{account: TradingAccount, signals_generated: int, market_ok: bool, entered: int, blocked: int, monitored: int, exits: int, portfolio: array<string, float>}
     */
    public function runSession(Collection $stocks, ?string $asOf = null, ?User $user = null): array
    {
        $account = $this->paperAccount($user);

        $scan = $this->signals->run($stocks, $asOf);

        $entered = 0;
        $blocked = 0;
        $freshPositionIds = [];

        foreach ($scan['generated'] as $signal) {
            $result = $this->execution->enter($signal, $account);

            if ($result['ok'] && $result['position'] instanceof Position) {
                $entered++;
                $freshPositionIds[] = $result['position']->id;
                $this->mirrorEntry($signal, $result['position']);
            } else {
                $blocked++;
            }
        }

        $monitor = $this->monitorOpen($account, $freshPositionIds);

        $this->reconcile($account);

        return [
            'account' => $account,
            'signals_generated' => count($scan['generated']),
            'market_ok' => $scan['marketOk'],
            'entered' => $entered,
            'blocked' => $blocked,
            'monitored' => $monitor['monitored'],
            'exits' => $monitor['exits'],
            'portfolio' => $this->portfolio->summary($account->id),
        ];
    }

    /**
     * Monitor every open position (except those just entered today) against the
     * latest stored close and let the ExecutionEngine book any SL / target exits.
     *
     * @param  array<int, string>  $bounceIds  position ids entered during this session
     * @return array{monitored: int, exits: int}
     */
    protected function monitorOpen(TradingAccount $account, array $bounceIds): array
    {
        $open = $this->portfolio->openPositions($account->id);
        $priceMap = $this->priceMap($open);

        $monitored = 0;
        $exits = 0;

        foreach ($open as $position) {
            if (in_array($position->id, $bounceIds, true)) {
                continue; // entered today; swing rules act from tomorrow's close
            }

            $price = $priceMap[$position->stock_id] ?? null;
            if ($price === null) {
                continue;
            }

            $monitored++;
            $outcome = $this->execution->monitorPosition($position, (float) $price);

            if (in_array($outcome['action'], ['stop_loss', 'target3', 'manual_close'], true)) {
                $exits++;
                $this->mirrorClose($position, $outcome['action']);
            }
        }

        return compact('monitored', 'exits');
    }

    /**
     * Mark-to-market against the latest close so accounting matches reality.
     *
     * @param  Collection<int, Position>  $positions
     * @return array<string, float>
     */
    protected function priceMap(Collection $positions): array
    {
        $map = [];

        foreach ($positions as $position) {
            if (isset($map[$position->stock_id])) {
                continue;
            }

            $stock = $position->stock;
            $latest = $stock ? $this->marketData->latestClose($stock) : null;
            if ($latest !== null) {
                $map[$position->stock_id] = (float) $latest;
            }
        }

        return $map;
    }

    /**
     * available_cash = starting capital + realized net P&L − currently invested.
     */
    protected function reconcile(TradingAccount $account): void
    {
        $realized = (float) Position::where('trading_account_id', $account->id)
            ->where('status', 'closed')
            ->sum('net_pnl');

        $invested = (float) Position::where('trading_account_id', $account->id)
            ->where('status', 'open')
            ->sum('entry_value');

        $available = (float) $account->starting_capital + $realized - $invested;

        $account->update([
            'available_cash' => round($available, 2),
            'invested_amount' => round($invested, 2),
        ]);
    }

    /**
     * Mirror an entry into the paper_trades ledger.
     */
    protected function mirrorEntry(TradingSignal $signal, Position $position): void
    {
        $order = Order::where('trading_signal_id', $signal->id)
            ->where('side', 'buy')
            ->latest('id')
            ->first();

        $positionStock = $position->stock;
        $signalStock = $signal->stock;
        $symbol = $positionStock !== null
            ? $positionStock->symbol
            : ($signalStock !== null ? $signalStock->symbol : 'UNKNOWN');

        PaperTrade::create([
            'trading_account_id' => $position->trading_account_id,
            'trading_signal_id' => $signal->id,
            'position_id' => $position->id,
            'symbol' => $symbol,
            'direction' => 'buy',
            'signal_price' => $signal->price,
            'intended_entry' => $signal->price,
            'fill_price' => $position->avg_entry_price,
            'quantity' => $position->quantity,
            'stop_loss' => $position->stop_loss,
            'target' => $position->target3,
            'slippage' => $order !== null ? (float) $order->slippage : 0,
            'status' => 'open',
            'entry_reason' => is_array($signal->entry_reasons) ? json_encode($signal->entry_reasons) : null,
            'signal_at' => $signal->signal_at ?? $position->opened_at,
            'executed_at' => $position->opened_at,
            'simulation_data' => ['score' => $signal->score, 'position_id' => $position->id],
        ]);
    }

    /**
     * Mark the mirrored paper trade closed once the ExecutionEngine exits.
     */
    protected function mirrorClose(Position $position, string $exitReason): void
    {
        PaperTrade::where('position_id', $position->id)
            ->where('status', 'open')
            ->update([
                'status' => 'closed',
                'exit_reason' => $exitReason,
                'exited_at' => now(),
                'pnl' => $position->realized_pnl,
                'pnl_net' => $position->net_pnl,
            ]);
    }
}
