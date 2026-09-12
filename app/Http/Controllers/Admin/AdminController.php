<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\AdminDashboardService;
use Inertia\Inertia;
use Inertia\Response;

class AdminController extends Controller
{
    public function __construct(protected AdminDashboardService $dashboard) {}

    /**
     * Company-admin console landing: platform summary + per-user profit rows.
     */
    public function index(): Response
    {
        $users = $this->dashboard->userMasterTable();

        return Inertia::render('admin/index', [
            'users' => $users,
            'summary' => [
                'users' => count($users),
                'total_realized_pnl_net' => array_sum(array_column(array_column($users, 'totals'), 'realized_pnl_net')),
                'total_unrealized_pnl_net' => array_sum(array_column(array_column($users, 'totals'), 'unrealized_pnl_net')),
                'total_orders' => array_sum(array_column(array_column($users, 'totals'), 'orders_count')),
                'total_signals' => array_sum(array_column(array_column($users, 'totals'), 'signals_count')),
                'total_open_positions' => array_sum(array_column(array_column($users, 'totals'), 'open_positions')),
            ],
        ]);
    }
}
