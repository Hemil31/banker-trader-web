<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\AdminDashboardService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

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

    /**
     * App-level integration settings (News API key, ...) — kept off the
     * per-user mobile config API, editable only from here.
     */
    public function settings(): Response
    {
        return Inertia::render('admin/settings', [
            'settings' => $this->dashboard->systemSettings(),
        ]);
    }

    public function updateSetting(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'key' => ['required', 'string', 'max:120'],
            'value' => ['required', 'string', 'max:2000'],
        ]);

        try {
            $this->dashboard->updateSystemSetting(
                $validated['key'],
                $validated['value'],
                (string) $request->user()->id,
            );
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['key' => $e->getMessage()]);
        }

        return back()->with('success', 'Setting updated.');
    }
}
