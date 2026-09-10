<?php

namespace App\Http\Controllers\Api\Trading;

use App\Http\Controllers\Controller;
use App\Services\TradingDashboardService;
use App\Traits\ResponseStructure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PortfolioController extends Controller
{
    use ResponseStructure;

    public function __construct(protected TradingDashboardService $dashboard) {}

    public function index(Request $request): JsonResponse
    {
        return $this->successResponse($this->dashboard->overview($request->user()), 'Portfolio overview');
    }
}
