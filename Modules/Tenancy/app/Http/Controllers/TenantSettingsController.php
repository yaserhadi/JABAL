<?php

namespace Modules\Tenancy\Http\Controllers;

use App\Http\Auth\TenantEntryUrlResolver;
use App\Http\Auth\TenantInertiaProps;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Modules\Api\Http\ApiResponse;
use Modules\Tenancy\Http\Requests\UpdateTenantSettingsRequest;
use Modules\Tenancy\Services\TenantSettingsService;

/**
 * Phase 3D / BK-028: Tenant-admin settings (tenant app_settings per DEC-0011).
 */
class TenantSettingsController extends Controller
{
    public function __construct(
        protected TenantSettingsService $tenantSettings,
        protected TenantEntryUrlResolver $tenantEntryUrls,
    ) {
        $this->middleware('permission:tenant.settings.view')->only(['show']);
        $this->middleware('permission:tenant.settings.update')->only(['update']);
    }

    public function show(Request $request): InertiaResponse|JsonResponse
    {
        $tenant = tenancy()->tenant;
        if (! $tenant) {
            abort(404);
        }

        $settings = $this->tenantSettings->resolvedForTenant($tenant);
        $tenantData = TenantInertiaProps::from($tenant);

        if ($request->expectsJson()) {
            return ApiResponse::success($settings);
        }

        return Inertia::render('TenantSettings/Index', [
            'tenant' => $tenantData,
            'settings' => $settings,
            'supportedLocales' => config('app.supported_locales', ['en']),
        ]);
    }

    public function update(UpdateTenantSettingsRequest $request): RedirectResponse|JsonResponse
    {
        $tenant = tenancy()->tenant;
        if (! $tenant) {
            abort(404);
        }

        $this->tenantSettings->update($tenant, $request->validated());
        $settings = $this->tenantSettings->resolvedForTenant($tenant);

        if ($request->expectsJson()) {
            return ApiResponse::success($settings);
        }

        return redirect()
            ->to($this->tenantEntryUrls->namedRouteUrl('tenant.settings.index', $tenant))
            ->with('success', 'Settings updated.');
    }
}
