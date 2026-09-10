<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AdminDashboardService;
use App\Traits\ResponseStructure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminUserController extends Controller
{
    use ResponseStructure;

    public function __construct(
        protected AdminDashboardService $dashboard,
    ) {}

    /**
     * Company master table — every user with per-account profit/loss and usage.
     */
    public function index(Request $request): JsonResponse
    {
        $rows = $this->dashboard->userMasterTable();

        return $this->successResponse([
            'users' => $rows,
            'summary' => $this->summaryOf($rows),
        ], 'Users master table.');
    }

    /**
     * Single user detail (accounts breakdown + recent paper trades).
     */
    public function show(Request $request, string $user): JsonResponse
    {
        $userModel = User::findOrFail($user);

        return $this->successResponse($this->dashboard->userDetail($userModel), 'User detail.');
    }

    /**
     * @param  array<int, array<string, mixed>>  $users
     * @return array<string, mixed>
     */
    protected function summaryOf(array $users): array
    {
        return [
            'users' => count($users),
            'total_realized_pnl_net' => array_sum(array_column(array_column($users, 'totals'), 'realized_pnl_net')),
            'total_unrealized_pnl_net' => array_sum(array_column(array_column($users, 'totals'), 'unrealized_pnl_net')),
            'total_orders' => array_sum(array_column(array_column($users, 'totals'), 'orders_count')),
            'total_signals' => array_sum(array_column(array_column($users, 'totals'), 'signals_count')),
            'total_open_positions' => array_sum(array_column(array_column($users, 'totals'), 'open_positions')),
        ];
    }
}
