<?php

namespace Modules\Api\Http;

use App\Support\Context\RequestContext;
use Illuminate\Http\JsonResponse;

/**
 * Standard API response format (Phase 1).
 * Used by all API endpoints and aligned with exception handling.
 */
class ApiResponse
{
    /**
     * Success response with data.
     *
     * @param  array<string, mixed>|object  $data
     */
    public static function success($data = [], int $status = 200): JsonResponse
    {
        $requestContext = RequestContext::getInstance();

        return response()->json([
            'data' => $data,
            'meta' => [
                'request_id' => $requestContext->requestId(),
                'timestamp' => now()->toIso8601String(),
            ],
        ], $status);
    }

    /**
     * Error response (consistent with DomainException format).
     *
     * @param  array<string, mixed>  $details
     */
    public static function error(string $code, string $message, array $details = [], int $status = 400): JsonResponse
    {
        $requestContext = RequestContext::getInstance();

        return response()->json([
            'error' => [
                'code' => $code,
                'message' => $message,
                'details' => $details,
            ],
            'meta' => [
                'request_id' => $requestContext->requestId(),
                'timestamp' => now()->toIso8601String(),
            ],
        ], $status);
    }

    /**
     * Paginated response.
     *
     * @param  array<int, mixed>  $data
     * @param  array{current_page: int, per_page: int, total: int, last_page?: int}  $pagination
     */
    public static function paginated(array $data, array $pagination, int $status = 200): JsonResponse
    {
        $requestContext = RequestContext::getInstance();

        return response()->json([
            'data' => $data,
            'meta' => [
                'request_id' => $requestContext->requestId(),
                'timestamp' => now()->toIso8601String(),
            ],
            'pagination' => array_merge([
                'current_page' => 1,
                'per_page' => 15,
                'total' => 0,
            ], $pagination),
        ], $status);
    }
}
