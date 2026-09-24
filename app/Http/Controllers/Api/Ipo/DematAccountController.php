<?php

namespace App\Http\Controllers\Api\Ipo;

use App\Http\Controllers\Controller;
use App\Http\Requests\Ipo\StoreDematAccountRequest;
use App\Http\Requests\Ipo\UpdateDematAccountRequest;
use App\Services\DematAccountService;
use App\Traits\ResponseStructure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class DematAccountController extends Controller
{
    use ResponseStructure;

    public function __construct(protected DematAccountService $dematAccounts) {}

    /**
     * The user's linked NSDL/CDSL demat accounts.
     */
    public function index(Request $request): JsonResponse
    {
        return $this->successResponse($this->dematAccounts->list($request->user()), 'Demat accounts.');
    }

    public function store(StoreDematAccountRequest $request): JsonResponse
    {
        $demat = $this->dematAccounts->create($request->user(), $request->validated());

        return $this->successResponse($demat, 'Demat account linked.', Response::HTTP_CREATED);
    }

    public function update(UpdateDematAccountRequest $request, string $dematAccount): JsonResponse
    {
        $demat = $this->dematAccounts->update($request->user(), $dematAccount, $request->validated());

        return $this->successResponse($demat, 'Demat account updated.');
    }

    public function destroy(Request $request, string $dematAccount): JsonResponse
    {
        $this->dematAccounts->delete($request->user(), $dematAccount);

        return $this->successResponse(null, 'Demat account removed.');
    }

    /**
     * Mark a linked demat BO verified (real NSDL/CDSL verification can be wired later).
     */
    public function verify(Request $request, string $dematAccount): JsonResponse
    {
        $demat = $this->dematAccounts->verify($request->user(), $dematAccount);

        return $this->successResponse($demat, 'Demat account verified.');
    }
}
