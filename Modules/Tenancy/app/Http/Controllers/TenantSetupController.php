<?php

namespace Modules\Tenancy\Http\Controllers;

use App\Http\Auth\TenantEntryUrlResolver;
use App\Http\Auth\TenantInertiaProps;
use App\Http\Controllers\Controller;
use App\Support\Contracts\Tenancy\TenantSetupReadinessEvaluator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Modules\Api\Http\ApiResponse;
use Modules\Tenancy\Services\TenantSetupReadinessService;

class TenantSetupController extends Controller
{
    public function __construct(
        private readonly TenantSetupReadinessEvaluator $readiness,
        private readonly TenantSetupReadinessService $setupService,
        private readonly TenantEntryUrlResolver $tenantEntryUrls,
    ) {
        $this->middleware('permission:tenant.setup.view')->only(['show']);
        $this->middleware('permission:tenant.setup.update')->only(['complete']);
    }

    public function show(Request $request): InertiaResponse|JsonResponse
    {
        $tenant = tenancy()->tenant;
        if (! $tenant) {
            abort(404);
        }

        $evaluation = $this->readiness->evaluate((string) $tenant->id);
        $payload = [
            'tenant' => TenantInertiaProps::from($tenant),
            'readiness' => $evaluation,
            'tenant_status' => $tenant->status,
            'setup_grandfathered' => (bool) $tenant->setup_grandfathered,
        ];

        if ($request->expectsJson()) {
            return ApiResponse::success($payload);
        }

        return Inertia::render('TenantSetup/Index', $payload);
    }

    public function complete(Request $request): RedirectResponse|JsonResponse
    {
        $tenant = tenancy()->tenant;
        if (! $tenant) {
            abort(404);
        }

        $data = $request->validate([
            'setup_code' => ['required', 'string', 'max:64'],
        ]);

        $this->setupService->complete(
            (string) $tenant->id,
            $data['setup_code'],
            $request->user()?->id
        );

        if ($request->expectsJson()) {
            return ApiResponse::success($this->readiness->evaluate((string) $tenant->id));
        }

        return redirect()->to(
            $this->tenantEntryUrls->namedRouteUrl('tenant.setup.index', $tenant)
        );
    }
}
