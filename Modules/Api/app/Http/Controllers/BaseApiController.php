<?php

namespace Modules\Api\Http\Controllers;

use App\Http\Controllers\Controller;
use Modules\Api\Http\ApiResponse;

/**
 * Base API controller with standard response helpers.
 * All API controllers should extend this for consistent response format.
 */
abstract class BaseApiController extends Controller
{
    /**
     * Return a success JSON response.
     *
     * @param  array<string, mixed>|object  $data
     */
    protected function success($data = [], int $status = 200)
    {
        return ApiResponse::success($data, $status);
    }

    /**
     * Return an error JSON response.
     *
     * @param  array<string, mixed>  $details
     */
    protected function error(string $code, string $message, array $details = [], int $status = 400)
    {
        return ApiResponse::error($code, $message, $details, $status);
    }

    /**
     * Return a paginated JSON response.
     *
     * @param  array<int, mixed>  $data
     * @param  array{current_page: int, per_page: int, total: int, last_page?: int}  $pagination
     */
    protected function paginated(array $data, array $pagination, int $status = 200)
    {
        return ApiResponse::paginated($data, $pagination, $status);
    }
}
