<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\TradingAccount;
use App\Services\EmergencyControlService;
use App\Services\PositionReconciliationService;
use App\Services\TradingConfigService;
use App\Traits\ResponseStructure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Admin-only emergency controls (Step 19 of the platform spec): stop new
 * orders, cancel pending orders, force-exit every open position, and check
 * the current kill-switch/reconciliation state. Every action here is
 * platform-wide by default; pass account_id to scope to one account.
 *
 * cancel-pending and emergency-exit require an explicit confirm:true in the
 * body — these are destructive, so a bare click/request is not enough.
 */
class TradingSafetyController extends Controller
{
    use ResponseStructure;

    public function __construct(
        protected EmergencyControlService $emergency,
        protected TradingConfigService $config,
    ) {}

    public function status(): JsonResponse
    {
        return $this->successResponse([
            'trading_halted' => $this->config->bool('system.trading_halted', false),
            'halt_reason' => $this->config->get('system.halt_reason') ?: null,
        ], 'Safety status.');
    }

    public function halt(Request $request): JsonResponse
    {
        $reason = (string) $request->input('reason', 'manual_halt');

        $this->emergency->haltNewOrders(actor: (string) $request->user()->id, reason: $reason);

        return $this->successResponse(['trading_halted' => true, 'halt_reason' => $reason], 'Trading halted platform-wide.');
    }

    public function resume(Request $request): JsonResponse
    {
        $this->emergency->resumeNewOrders(actor: (string) $request->user()->id);

        return $this->successResponse(['trading_halted' => false], 'Trading resumed platform-wide.');
    }

    public function cancelPending(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'confirm' => ['required', 'accepted'],
            'account_id' => ['nullable', 'uuid', 'exists:trading_accounts,id'],
        ]);

        $account = isset($validated['account_id']) ? TradingAccount::findOrFail((string) $validated['account_id']) : null;

        $result = $this->emergency->cancelPendingOrders($account, actor: (string) $request->user()->id);

        return $this->successResponse($result, "Cancelled {$result['cancelled']}/{$result['total']} pending order(s).");
    }

    public function emergencyExit(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'confirm' => ['required', 'accepted'],
            'account_id' => ['nullable', 'uuid', 'exists:trading_accounts,id'],
        ]);

        $account = isset($validated['account_id']) ? TradingAccount::findOrFail((string) $validated['account_id']) : null;

        $result = $this->emergency->emergencyExitAll($account, actor: (string) $request->user()->id);

        return $this->successResponse($result, "Closed {$result['closed']}/{$result['total']} open position(s).");
    }

    public function reconcile(PositionReconciliationService $reconciliation): JsonResponse
    {
        $result = $reconciliation->reconcileAll();

        return $this->successResponse($result, "Checked {$result['checked']} account(s), {$result['mismatched']} mismatched.");
    }
}
