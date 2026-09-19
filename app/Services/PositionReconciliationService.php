<?php

namespace App\Services;

use App\Models\ErrorLog;
use App\Models\Position;
use App\Models\Stock;
use App\Models\SystemEvent;
use App\Models\TradingAccount;
use Illuminate\Support\Collection;

/**
 * Compares the DB's view of open positions against what the connected
 * broker actually reports for every externally-connected account. The DB
 * is never assumed correct: any mismatch (missing position, unexpected
 * position, or a quantity that doesn't match) halts new order entries
 * platform-wide (via EmergencyControlService's kill switch) and logs both
 * a SystemEvent (for the admin feed) and an ErrorLog (for alerting) — it
 * does not attempt to auto-correct either side, since fixing a position
 * mismatch is exactly the kind of thing a human needs to look at.
 */
class PositionReconciliationService
{
    public function __construct(
        protected BrokerManager $brokers,
        protected EmergencyControlService $emergency,
    ) {}

    /**
     * @return array{checked: int, mismatched: int, unreachable: int, accounts: array<int, array<string, mixed>>}
     */
    public function reconcileAll(): array
    {
        $accounts = TradingAccount::with('broker')
            ->whereNotNull('broker_id')
            ->get()
            ->filter(fn (TradingAccount $account) => $account->isLiveBrokerConnected());

        $checked = 0;
        $mismatched = 0;
        $unreachable = 0;
        $results = [];

        foreach ($accounts as $account) {
            $result = $this->reconcileAccount($account);
            $results[] = ['account_id' => $account->id, ...$result];

            if ($result['status'] === 'unreachable') {
                $unreachable++;

                continue;
            }

            $checked++;
            if ($result['status'] === 'mismatched') {
                $mismatched++;
            }
        }

        return compact('checked', 'mismatched', 'unreachable') + ['accounts' => $results];
    }

    /**
     * @return array{status: 'ok'|'mismatched'|'unreachable', mismatches: array<int, array<string, mixed>>}
     */
    public function reconcileAccount(TradingAccount $account): array
    {
        try {
            $brokerPositions = $this->brokers->activeAdapter($account)->getPositions();
        } catch (\Throwable $e) {
            ErrorLog::create([
                'level' => 'warning',
                'component' => 'reconciliation',
                'code' => 'broker_unreachable',
                'message' => "Could not fetch broker positions for reconciliation: {$e->getMessage()}",
                'context' => ['account_id' => $account->id],
                'logged_at' => now(),
            ]);

            return ['status' => 'unreachable', 'mismatches' => []];
        }

        $brokerMap = $this->normalizeBrokerPositions($brokerPositions);
        $dbMap = $this->normalizeDbPositions($account);

        $mismatches = [];
        foreach (array_unique([...array_keys($brokerMap), ...array_keys($dbMap)]) as $symbol) {
            $brokerQty = $brokerMap[$symbol] ?? 0.0;
            $dbQty = $dbMap[$symbol] ?? 0.0;

            if (abs($brokerQty - $dbQty) > 0.0001) {
                $mismatches[] = ['symbol' => $symbol, 'broker_qty' => $brokerQty, 'db_qty' => $dbQty];
            }
        }

        if ($mismatches === []) {
            return ['status' => 'ok', 'mismatches' => []];
        }

        $this->recordMismatch($account, $mismatches);

        return ['status' => 'mismatched', 'mismatches' => $mismatches];
    }

    /**
     * @param  array<int, array{symbol: string, quantity: float, avg_price: float, pnl?: float}>  $positions
     * @return array<string, float>
     */
    protected function normalizeBrokerPositions(array $positions): array
    {
        $map = [];

        foreach ($positions as $position) {
            $symbol = strtoupper(trim($position['symbol']));
            if ($symbol === '') {
                continue;
            }

            $map[$symbol] = ($map[$symbol] ?? 0.0) + (float) $position['quantity'];
        }

        return $map;
    }

    /**
     * @return array<string, float>
     */
    protected function normalizeDbPositions(TradingAccount $account): array
    {
        /** @var Collection<int, Position> $positions */
        $positions = Position::with('stock')
            ->where('trading_account_id', $account->id)
            ->where('status', 'open')
            ->get();

        $map = [];

        foreach ($positions as $position) {
            /** @var Stock|null $stock */
            $stock = $position->stock;
            $symbol = strtoupper(trim((string) ($stock->symbol ?? '')));
            if ($symbol === '') {
                continue;
            }

            $remaining = (float) $position->quantity - (float) ($position->partial_booked_qty ?? 0);
            $map[$symbol] = ($map[$symbol] ?? 0.0) + $remaining;
        }

        return $map;
    }

    /**
     * @param  array<int, array<string, mixed>>  $mismatches
     */
    protected function recordMismatch(TradingAccount $account, array $mismatches): void
    {
        ErrorLog::create([
            'level' => 'critical',
            'component' => 'reconciliation',
            'code' => 'position_mismatch',
            'message' => 'Broker-reported positions do not match the database for account '.$account->id,
            'context' => ['account_id' => $account->id, 'mismatches' => $mismatches],
            'logged_at' => now(),
        ]);

        SystemEvent::create([
            'type' => 'risk',
            'action' => 'reconciliation_mismatch',
            'subject_type' => TradingAccount::class,
            'subject_id' => $account->id,
            'description' => 'Position reconciliation mismatch for account '.$account->id.' — trading halted platform-wide',
            'data' => ['mismatches' => $mismatches],
        ]);

        $this->emergency->haltNewOrders(actor: 'reconciliation', reason: 'reconciliation_mismatch');
    }
}
