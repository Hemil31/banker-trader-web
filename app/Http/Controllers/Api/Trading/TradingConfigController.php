<?php

namespace App\Http\Controllers\Api\Trading;

use App\Http\Controllers\Controller;
use App\Http\Requests\Trading\TradingConfigUpdateRequest;
use App\Services\TradingDashboardService;
use App\Traits\ResponseStructure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TradingConfigController extends Controller
{
    use ResponseStructure;

    public function __construct(protected TradingDashboardService $dashboard) {}

    public function index(Request $request): JsonResponse
    {
        return $this->successResponse(
            $this->dashboard->configRows(editableOnly: true),
            'Trading configuration',
        );
    }

    public function update(TradingConfigUpdateRequest $request): JsonResponse
    {
        $this->dashboard->updateConfig($request->key, $request->typedValue(), (string) $request->user()->id);

        return $this->successResponse(
            ['key' => $request->key, 'value' => $request->typedValue()],
            'Config updated',
        );
    }
}
