<?php

namespace App\Support;

use App\Enums\ApiCode;
use Illuminate\Http\JsonResponse;

/**
 * The one place that shapes an API payload.
 *
 * Both the ApiResponse trait (controllers) and the global exception handlers use
 * this, so a failure raised deep in a service comes back looking exactly like a
 * failure returned deliberately from a controller.
 */
class ApiEnvelope
{
    public static function error(
        string $message,
        int $status = 400,
        string $code = ApiCode::GENERIC,
        array $data = []
    ): JsonResponse {
        return response()->json([
            'error' => true,
            'code' => $code,
            'message' => $message,
            'data' => $data,
            'meta' => new \stdClass,
        ], $status);
    }

    public static function success(
        mixed $data = [],
        string $message = 'Operation successful',
        int $status = 200,
        string $code = ApiCode::GENERIC,
        array $meta = []
    ): JsonResponse {
        return response()->json([
            'error' => false,
            'code' => $code,
            'message' => $message,
            'data' => $data,
            'meta' => empty($meta) ? new \stdClass : $meta,
        ], $status);
    }
}
