<?php

namespace App\Http\Controllers\Api\Ipo;

use App\Http\Controllers\Controller;
use App\Http\Requests\Ipo\StorePanCardRequest;
use App\Http\Requests\Ipo\UpdatePanCardRequest;
use App\Services\PanCardService;
use App\Traits\ResponseStructure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class PanCardController extends Controller
{
    use ResponseStructure;

    public function __construct(protected PanCardService $panCards) {}

    /**
     * The user's linked PAN cards.
     */
    public function index(Request $request): JsonResponse
    {
        return $this->successResponse($this->panCards->list($request->user()), 'PAN cards.');
    }

    public function store(StorePanCardRequest $request): JsonResponse
    {
        $pan = $this->panCards->create($request->user(), $request->validated());

        return $this->successResponse($pan, 'PAN card linked.', Response::HTTP_CREATED);
    }

    public function update(UpdatePanCardRequest $request, string $panCard): JsonResponse
    {
        $pan = $this->panCards->update($request->user(), $panCard, $request->validated());

        return $this->successResponse($pan, 'PAN card updated.');
    }

    public function destroy(Request $request, string $panCard): JsonResponse
    {
        $this->panCards->delete($request->user(), $panCard);

        return $this->successResponse(null, 'PAN card removed.');
    }

    /**
     * Mark a linked PAN verified (real PAN verification API can be wired later).
     */
    public function verify(Request $request, string $panCard): JsonResponse
    {
        $pan = $this->panCards->verify($request->user(), $panCard);

        return $this->successResponse($pan, 'PAN card verified.');
    }
}
