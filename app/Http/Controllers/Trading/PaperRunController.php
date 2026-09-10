<?php

namespace App\Http\Controllers\Trading;

use App\Http\Controllers\Controller;
use App\Services\TradingDashboardService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class PaperRunController extends Controller
{
    public function __construct(protected TradingDashboardService $dashboard) {}

    public function store(Request $request): RedirectResponse
    {
        $result = $this->dashboard->runSession(null, $request->user());

        return back()->with('toast', [
            'type' => 'success',
            'message' => sprintf(
                'Session complete — %d entered, %d blocked, %d monitored, %d exits.',
                $result['entered'],
                $result['blocked'],
                $result['monitored'],
                $result['exits'],
            ),
        ]);
    }
}
