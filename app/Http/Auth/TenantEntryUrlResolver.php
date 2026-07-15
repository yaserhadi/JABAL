<?php

namespace App\Http\Auth;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Modules\Tenancy\Models\Tenant;

/**
 * BK-066: Focused authority for tenant entry URLs, redirect tip resolution, and safe intended URLs.
 *
 * Does not own: status eligibility policy, membership, authentication, tenancy init, or platform auth.
 */
final class TenantEntryUrlResolver
{
    public function entryKey(Tenant $tenant): string
    {
        return $tenant->entryKey();
    }

    public function loginUrl(Tenant $tenant): string
    {
        return route('tenant.login', ['tenant' => $this->entryKey($tenant)]);
    }

    public function dashboardUrl(Tenant $tenant): string
    {
        return route('dashboard', ['tenant' => $this->entryKey($tenant)]);
    }

    /**
     * Resolve tenant tip for redirects (logout / guest). Precedence per BK-066 plan.
     */
    public function resolveTenantForRedirect(Request $request): ?Tenant
    {
        $fromRoute = $this->tenantFromRouteOrPath($request);
        if ($fromRoute !== null) {
            return $fromRoute;
        }

        if (tenancy()->initialized && tenancy()->tenant instanceof Tenant) {
            return tenancy()->tenant;
        }

        return $this->tenantFromSessionHint($request);
    }

    /**
     * Guest HTML redirect target. Platform → platform login; active tenant tip → tenant login;
     * inactive tip → tenant login URL (existing showTenantLogin → 404); otherwise central login.
     */
    public function guestRedirectUrl(Request $request): string
    {
        if ($request->is('platform') || $request->is('platform/*')) {
            return route('platform.login');
        }

        $tenant = $this->resolveTenantForRedirect($request);

        if ($tenant !== null) {
            if ($this->isActiveForTenantLogin($tenant)) {
                $this->rememberIntended($request, $tenant);

                return $this->loginUrl($tenant);
            }

            // Inactive/suspended: do not send to central discovery; same path as visiting tenant login.
            return $this->loginUrl($tenant);
        }

        return route('login');
    }

    /**
     * Existing AuthController eligibility rule (status === active). Do not invent other matrices here.
     */
    public function isActiveForTenantLogin(Tenant $tenant): bool
    {
        return $tenant->status === 'active';
    }

    public function isSafeIntendedUrl(Request $request, Tenant $tenant, string $url): bool
    {
        $parts = parse_url($url);
        if ($parts === false) {
            return false;
        }

        if (isset($parts['scheme']) || isset($parts['host'])) {
            $appUrl = parse_url($request->root());
            $scheme = $parts['scheme'] ?? ($appUrl['scheme'] ?? 'http');
            $host = $parts['host'] ?? null;
            if ($host === null || ! isset($appUrl['host'])) {
                return false;
            }
            if (strcasecmp((string) $host, (string) $appUrl['host']) !== 0) {
                return false;
            }
            $appScheme = $appUrl['scheme'] ?? 'http';
            if (strcasecmp((string) $scheme, (string) $appScheme) !== 0) {
                return false;
            }
        }

        $path = $parts['path'] ?? '';
        if (! is_string($path) || $path === '') {
            return false;
        }

        if (! preg_match('#^/t/([^/]+)(/.*)?$#', $path, $m)) {
            return false;
        }

        $key = rawurldecode($m[1]);
        $resolved = $this->findTenantByKey($key);

        return $resolved !== null && $resolved->id === $tenant->id;
    }

    public function rememberIntended(Request $request, Tenant $tenant): void
    {
        if (! $request->isMethod('GET')) {
            return;
        }

        $url = $request->fullUrl();
        if (! $this->isSafeIntendedUrl($request, $tenant, $url)) {
            return;
        }

        $request->session()->put('url.intended', $url);
    }

    public function clearIntended(Request $request): void
    {
        $request->session()->forget('url.intended');
    }

    /**
     * Post-login redirect: safe intended or tenant dashboard.
     */
    public function redirectAfterLogin(Request $request, Tenant $tenant): \Illuminate\Http\RedirectResponse
    {
        $intended = $request->session()->pull('url.intended');
        if (is_string($intended) && $intended !== '' && $this->isSafeIntendedUrl($request, $tenant, $intended)) {
            return redirect()->to($intended);
        }

        return redirect()->to($this->dashboardUrl($tenant));
    }

    private function tenantFromRouteOrPath(Request $request): ?Tenant
    {
        $routeTenant = $request->route('tenant');

        if ($routeTenant instanceof Tenant) {
            return $routeTenant;
        }

        $key = is_string($routeTenant) && $routeTenant !== ''
            ? $routeTenant
            : ($request->segment(1) === 't' ? $request->segment(2) : null);

        if (! is_string($key) || $key === '') {
            return null;
        }

        return $this->findTenantByKey($key);
    }

    private function tenantFromSessionHint(Request $request): ?Tenant
    {
        if (! $request->hasSession()) {
            return null;
        }

        $id = $request->session()->get('tenant_id');
        if (! is_string($id) || $id === '' || ! Str::isUuid($id)) {
            return null;
        }

        return Tenant::query()->find($id);
    }

    private function findTenantByKey(string $key): ?Tenant
    {
        if (Str::isUuid($key)) {
            return Tenant::query()->find($key);
        }

        return Tenant::query()->where('slug', $key)->first();
    }
}
