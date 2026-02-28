<?php

namespace Modules\Api\Helpers;

use App\Support\Context\RequestContext;
use Illuminate\Http\JsonResponse;

/**
 * API Response Helper.
 *
 * Provides static methods for standardized API responses.
 * All responses include request_id from RequestContext and timestamp in meta.
 */
class ApiResponse
{
    /**
     * Success response with data.
     *
     * @param  array<string, mixed>|object  $data
     * @param  array<string, mixed>  $meta  Additional metadata to include
     */
    public static function success($data, array $meta = []): JsonResponse
    {
        $requestContext = RequestContext::getInstance();

        return response()->json([
            'data' => $data,
            'meta' => array_merge([
                'request_id' => $requestContext->requestId(),
                'timestamp' => now()->toIso8601String(),
            ], $meta),
        ], 200);
    }

    /**
     * Error response.
     *
     * @param  string  $code  Error code
     * @param  string  $message  Human-readable error message
     * @param  array<string, mixed>  $details  Additional error details
     */
    public static function error(string $code, string $message, array $details = []): JsonResponse
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
        ], 400);
    }

    /**
     * Paginated response.
     *
     * @param  array<int, mixed>  $data  Array of data items
     * @param  array<string, mixed>  $pagination  Pagination information
     * @param  array<string, mixed>  $meta  Additional metadata to include
     */
    public static function paginated(array $data, array $pagination, array $meta = []): JsonResponse
    {
        $requestContext = RequestContext::getInstance();

        return response()->json([
            'data' => $data,
            'meta' => array_merge([
                'request_id' => $requestContext->requestId(),
                'timestamp' => now()->toIso8601String(),
            ], $meta),
            'pagination' => $pagination,
        ], 200);
    }
}
