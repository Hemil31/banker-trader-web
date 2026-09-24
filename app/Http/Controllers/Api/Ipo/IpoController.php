<?php

namespace App\Http\Controllers\Api\Ipo;

use App\Http\Controllers\Controller;
use App\Services\IpoService;
use App\Traits\ResponseStructure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class IpoController extends Controller
{
    use ResponseStructure;

    public function __construct(protected IpoService $ipos) {}

    /**
     * List IPOs. Optional ?status= and ?board=mainboard|sme filters.
     */
    public function index(Request $request): JsonResponse
    {
        $status = $request->query('status');
        $board = $request->query('board');
        $perPage = min(50, max(1, (int) ($request->query('per_page') ?? 15)));

        return $this->paginated(
            $this->ipos->list(
                is_string($status) ? $status : null,
                is_string($board) ? $board : null,
                $perPage,
            ),
            'IPOs',
        );
    }

    /**
     * Detail page for one IPO, with day-wise subscription and GMP history.
     */
    public function show(string $slug): JsonResponse
    {
        return $this->successResponse($this->ipos->findBySlug($slug), 'IPO details.');
    }
}
