<?php

namespace App\Http\Controllers\Trading;

use App\Http\Controllers\Controller;
use App\Services\TradingDashboardService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PaperTradesController extends Controller
{
    public function __construct(protected TradingDashboardService $dashboard) {}

    public function index(Request $request): Response
    {
        return Inertia::render('trading/paper-trades', [
            'trades' => $this->dashboard->paperTrades(100),
        ]);
    }
}
