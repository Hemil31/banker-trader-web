<?php

namespace App\Http\Controllers\Trading;

use App\Http\Controllers\Controller;
use App\Http\Requests\Trading\TradingConfigUpdateRequest;
use App\Services\TradingDashboardService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TradingConfigController extends Controller
{
    public function __construct(protected TradingDashboardService $dashboard) {}

    public function index(Request $request): Response
    {
        return Inertia::render('trading/config', [
            'rows' => $this->dashboard->configRows(),
        ]);
    }

    public function update(TradingConfigUpdateRequest $request): RedirectResponse
    {
        $this->dashboard->updateConfig($request->key, $request->typedValue(), $request->user()->email);

        return back()->with('toast', [
            'type' => 'success',
            'message' => "Config updated: {$request->key}",
        ]);
    }
}
