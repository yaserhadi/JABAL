<?php

namespace Modules\Identity\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Api\Http\ApiResponse;
use Modules\Identity\Http\Requests\UpdateSecurityPolicyRequest;
use Modules\Identity\Services\SecurityPolicyService;

/**
 * BK-043 / DEC-0011: Tenant-facing security policies API.
 */
class SecurityPolicyController extends Controller
{
    public function __construct(protected SecurityPolicyService $service)
    {
        $this->middleware('permission:tenant.security-policy.view')->only(['show']);
        $this->middleware('permission:tenant.security-policy.update')->only(['update']);
    }

    public function show(Request $request): JsonResponse
    {
        $tenant = tenancy()->tenant;
        if (! $tenant) {
            abort(404);
        }

        $policies = $this->service->getForTenant($tenant);

        return ApiResponse::success($policies);
    }

    public function update(UpdateSecurityPolicyRequest $request): JsonResponse
    {
        $tenant = tenancy()->tenant;
        if (! $tenant) {
            abort(404);
        }

        $this->service->update($tenant, $request->validated());
        $policies = $this->service->getForTenant($tenant);

        return ApiResponse::success($policies);
    }
}
