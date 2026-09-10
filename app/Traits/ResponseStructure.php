<?php

namespace App\Traits;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

trait ResponseStructure
{
    /**
     * Build a success response.
     */
    protected function successResponse(
        mixed $data,
        ?string $message = null,
        int $code = Response::HTTP_OK,
    ): JsonResponse {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $data,
        ], $code);
    }

    /**
     * Build an error response.
     */
    protected function errorResponse(
        int $code,
        ?string $message = null,
        mixed $errors = null,
    ): JsonResponse {
        return response()->json([
            'success' => false,
            'message' => $message,
            'errors' => $errors,
        ], $code);
    }

    /**
     * Build a paginated response.
     *
     * @param  LengthAwarePaginator<int, mixed>  $paginator
     * @param  array<string, string>  $fields
     */
    protected function paginated(
        LengthAwarePaginator $paginator,
        string $message = 'Success',
        array $fields = [],
        int $code = Response::HTTP_OK,
    ): JsonResponse {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $paginator->items(),
            'pagination' => [
                'page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
                'next_page' => $paginator->currentPage() < $paginator->lastPage() ? $paginator->currentPage() + 1 : null,
                'prev_page' => $paginator->currentPage() > 1 ? $paginator->currentPage() - 1 : null,
            ],
        ], $code);
    }
}
