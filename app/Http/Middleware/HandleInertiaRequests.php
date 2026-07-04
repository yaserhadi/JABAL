<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;
use Modules\Tenancy\Services\TenantSettingsService;
use Spatie\Permission\PermissionRegistrar;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determine the current asset version.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'appName' => config('app.name'),
            'auth' => [
                'user' => fn () => $request->user(),
            ],
            'personalTenant' => fn () => $request->user()?->personalTenant(),
            'csrf_token' => fn () => csrf_token(),
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
            ],
            'tenantBranding' => fn () => $this->sharedTenantBranding(),
            'tenant_ui_permissions' => fn () => $this->sharedTenantUiPermissions($request),
        ];
    }

    /**
     * Read-only branding for shell (Phase 3D); no tenant.settings.view required.
     *
     * @return array{display_name: string, branding_logo_url: ?string}|null
     */
    protected function sharedTenantBranding(): ?array
    {
        if (! function_exists('tenancy') || ! tenancy()->initialized) {
            return null;
        }
        $tenant = tenancy()->tenant;

        return $tenant ? app(TenantSettingsService::class)->forShell($tenant) : null;
    }

    /**
     * @return array{canViewTenantSettings: bool, canUpdateTenantSettings: bool, canViewTenantAudit: bool, canViewSecurityPolicies: bool, canUpdateSecurityPolicies: bool}
     */
    protected function sharedTenantUiPermissions(Request $request): array
    {
        $default = [
            'canViewTenantSettings' => false,
            'canUpdateTenantSettings' => false,
            'canViewTenantAudit' => false,
            'canViewSecurityPolicies' => false,
            'canUpdateSecurityPolicies' => false,
        ];
        $user = $request->user();
        if (! $user || ! function_exists('tenancy') || ! tenancy()->initialized) {
            return $default;
        }
        $tenant = tenancy()->tenant;
        if (! $tenant) {
            return $default;
        }
        $registrar = app(PermissionRegistrar::class);
        $previousTeamId = $registrar->getPermissionsTeamId();
        $registrar->setPermissionsTeamId($tenant->getTenantKey());
        try {
            return [
                'canViewTenantSettings' => $user->can('tenant.settings.view'),
                'canUpdateTenantSettings' => $user->can('tenant.settings.update'),
                'canViewTenantAudit' => $user->can('tenant.audit.view'),
                'canViewSecurityPolicies' => $user->can('tenant.security-policy.view'),
                'canUpdateSecurityPolicies' => $user->can('tenant.security-policy.update'),
            ];
        } finally {
            $registrar->setPermissionsTeamId($previousTeamId);
        }
    }
}
