<?php

namespace Modules\Identity\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Modules\Api\Http\ApiResponse;
use Modules\Identity\Http\Requests\UpdateSsoConfigRequest;
use Modules\Identity\Services\SsoConfigService;

/**
 * BK-008: Tenant SSO configuration admin API (safe fields only).
 */
class SsoConfigController extends Controller
{
    public function __construct(protected SsoConfigService $service)
    {
        $this->middleware('permission:tenant.sso.view')->only(['show']);
        $this->middleware('permission:tenant.sso.update')->only(['update']);
    }

    public function show(Request $request): JsonResponse
    {
        $tenant = tenancy()->tenant;
        if (! $tenant) {
            abort(404);
        }

        return ApiResponse::success($this->service->getForTenant($tenant));
    }

    public function update(UpdateSsoConfigRequest $request): JsonResponse|RedirectResponse
    {
        $tenant = tenancy()->tenant;
        if (! $tenant) {
            abort(404);
        }

        $this->service->update($tenant, $request->validatedForService());
        $payload = $this->service->getForTenant($tenant);

        if ($request->header('X-Inertia')) {
            return redirect()
                ->route('identity.security-settings.show', ['tenant' => $tenant->entryKey()])
                ->with('success', 'SSO configuration updated.');
        }

        return ApiResponse::success($payload);
    }
}
