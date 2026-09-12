<?php

namespace App\Http\Controllers\Api\Trading;

use App\Http\Controllers\Controller;
use App\Services\TradingDashboardService;
use App\Traits\ResponseStructure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PositionsController extends Controller
{
    use ResponseStructure;

    public function __construct(protected TradingDashboardService $dashboard) {}

    public function index(Request $request): JsonResponse
    {
        $status = $request->query('status');

        $positions = $this->dashboard->positions(
            is_string($status) && $status !== '' ? $status : null,
            100,
            $request->user(),
        );

        return $this->successResponse($positions, 'Positions');
    }
}
