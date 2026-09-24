<?php

namespace App\Http\Controllers\Api\Ipo;

use App\Exceptions\IpoFlowException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Ipo\StoreIpoApplicationsRequest;
use App\Services\IpoAllotmentService;
use App\Services\IpoApplicationService;
use App\Traits\ResponseStructure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class IpoApplicationController extends Controller
{
    use ResponseStructure;

    public function __construct(
        protected IpoApplicationService $applications,
        protected IpoAllotmentService $allotments,
    ) {}

    /**
     * The user's IPO applications (with IPO, PAN, demat and allotment result).
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = min(50, max(1, (int) ($request->query('per_page') ?? 15)));

        return $this->paginated($this->applications->forUser($request->user(), $perPage), 'IPO applications.');
    }

    /**
     * Bulk-apply: create one application per requested IPO in a single batch.
     */
    public function store(StoreIpoApplicationsRequest $request): JsonResponse
    {
        $result = $this->applications->createBulk($request->user(), $request->validated());

        return $this->successResponse($result, 'Applications queued for submission.', Response::HTTP_CREATED);
    }

    /**
     * Run the allotment check for one of the user's applications.
     */
    public function checkAllotment(Request $request, string $ipoApplication): JsonResponse
    {
        $application = $this->applications->scoped($request->user(), $ipoApplication);

        try {
            $allotment = $this->allotments->check($application);
        } catch (IpoFlowException $e) {
            return $this->errorResponse(Response::HTTP_UNPROCESSABLE_ENTITY, $e->getMessage());
        }

        return $this->successResponse($allotment->loadMissing('application'), 'Allotment check completed.');
    }
}
