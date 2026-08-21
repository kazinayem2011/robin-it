<?php

namespace App\Traits;

use App\Enums\ApiCode;
use App\Exceptions\StorefrontException;
use App\Support\ApiEnvelope;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;

trait ApiResponse
{
    /**
     * Return a standardized JSON response.
     *
     * @param  mixed  $data
     */
    protected function successResponse(
        $data = [],
        string $message = 'Operation successful',
        int $statusCode = 200,
        string $code = ApiCode::GENERIC,
        array $meta = []
    ): JsonResponse {
        return ApiEnvelope::success($data, $message, $statusCode, $code, $meta);
    }

    /**
     * Return a standardized error response.
     *
     * @param  mixed  $data
     */
    protected function errorResponse(
        string $message = 'Operation failed',
        int $statusCode = 400,
        string $code = ApiCode::GENERIC,
        $data = []
    ): JsonResponse {
        return ApiEnvelope::error($message, $statusCode, $code, is_array($data) ? $data : ['value' => $data]);
    }

    /**
     * Paginated collections get the same envelope as everything else: the rows go in
     * `data`, the page info goes in `meta`. Clients no longer need a second shape
     * with `data.data` / `data.links` nested inside.
     */
    protected function paginatedResponse(
        LengthAwarePaginator $paginator,
        string $message = 'Operation successful',
        ?callable $transform = null
    ): JsonResponse {
        $items = collect($paginator->items());

        if ($transform) {
            $items = $items->map($transform);
        }

        return $this->successResponse($items->values(), $message, 200, ApiCode::GENERIC, [
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'from' => $paginator->firstItem(),
            'to' => $paginator->lastItem(),
            'has_more' => $paginator->hasMorePages(),
        ]);
    }

    /**
     * Render a customer-facing storefront problem (out of stock, expired coupon…)
     * with its code and any extra context the UI needs.
     */
    protected function storefrontErrorResponse(StorefrontException $e): JsonResponse
    {
        return $this->errorResponse(
            $e->getMessage(),
            $e->status(),
            $e->errorCode(),
            $e->context()
        );
    }
}
