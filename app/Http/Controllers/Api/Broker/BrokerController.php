<?php

namespace App\Http\Controllers\Api\Broker;

use App\Http\Controllers\Controller;
use App\Models\Broker;
use App\Services\BrokerOAuthService;
use App\Traits\ResponseStructure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BrokerController extends Controller
{
    use ResponseStructure;

    public function __construct(protected BrokerOAuthService $brokerOAuth) {}

    /**
     * List brokers available on the platform.
     */
    public function index(): JsonResponse
    {
        $brokers = Broker::active()
            ->orderBy('name')
            ->get(['slug', 'name', 'paper', 'active']);

        return $this->successResponse($brokers, 'Brokers retrieved successfully.');
    }

    /**
     * Connection status for the given user's trading account.
     */
    public function status(Request $request, string $tradingAccount): JsonResponse
    {
        $account = $request->user()->tradingAccounts()->with('broker')->findOrFail($tradingAccount);

        return $this->successResponse($this->brokerOAuth->status($account), 'Broker status retrieved successfully.');
    }

    /**
     * The user's trading accounts with their broker connection state.
     *
     * @return JsonResponse data: [{id, name, connected, broker, connected_at, mode}, ...]
     */
    public function accounts(Request $request): JsonResponse
    {
        $accounts = $request->user()->tradingAccounts()
            ->with('broker')
            ->get()
            ->map(fn ($account) => [
                'id' => $account->id,
                'name' => $account->name,
                'mode' => $account->mode,
            ] + $this->brokerOAuth->status($account));

        return $this->successResponse($accounts->values(), 'Trading accounts retrieved successfully.');
    }
}
