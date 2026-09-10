<?php

namespace App\Http\Controllers\Api\Trading;

use App\Http\Controllers\Controller;
use App\Services\TradingDashboardService;
use App\Traits\ResponseStructure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaperRunController extends Controller
{
    use ResponseStructure;

    public function __construct(protected TradingDashboardService $dashboard) {}

    public function store(Request $request): JsonResponse
    {
        $symbols = $request->input('symbols');
        $symbolIds = is_array($symbols) ? array_map('intval', $symbols) : null;

        $result = $this->dashboard->runSession($symbolIds, $request->user());

        return $this->successResponse([
            'signals_generated' => $result['signals_generated'],
            'market_ok' => $result['market_ok'],
            'entered' => $result['entered'],
            'blocked' => $result['blocked'],
            'monitored' => $result['monitored'],
            'exits' => $result['exits'],
            'portfolio' => $result['portfolio'],
        ], 'Paper session complete');
    }
}
