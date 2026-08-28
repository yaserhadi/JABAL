<?php

namespace App\Http\Auth;

use App\Support\Tenancy\TenantAddressingProfile;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Modules\Tenancy\Models\Tenant;

/**
 * BK-066/BK-073: Focused authority for tenant entry URLs, redirect tip resolution, and safe intended URLs.
 *
 * All URL-producing methods return absolute canonical URLs in BOTH profiles
 * from the configured canonical origin — never from the untrusted request Host.
 *
 * Does not own: status eligibility policy, membership, authentication, tenancy init, or platform auth.
 */
final class TenantEntryUrlResolver
{
    public function __construct(
        private readonly TenantAddressingProfile $addressing,
    ) {}

    public function entryKey(Tenant $tenant): string
    {
        return $tenant->entryKey();
    }

    /**
     * Absolute canonical Tenant entry URL (no trailing slash).
     * Host: https://{handle}.{base}
     * Path: https://{platform}/t/{handle}.
     */
    public function entryUrl(Tenant $tenant): string
    {
        return $this->entryUrlForHandle($this->entryKey($tenant));
    }

    /**
     * Absolute canonical entry URL preview for a Handle (Create UI — no Tenant row required).
     */
    public function entryUrlForHandle(string $handle): string
    {
        $key = strtolower(trim($handle));
        if ($key === '') {
            return '';
        }

        if ($this->addressing->isHost()) {
            return $this->addressing->absoluteOriginForHost(
                $this->addressing->tenantHostFqdn($key)
            );
        }

        return $this->addressing->absoluteOriginForHost($this->addressing->platformHost())
            .'/t/'.$key;
    }

    /**
     * Path-only helper for internal use — not the primary URL contract.
     */
    public function entryPath(Tenant $tenant): string
    {
        return '/t/'.$this->entryKey($tenant);
    }

    public function loginUrl(Tenant $tenant): string
    {
        if ($this->addressing->isHost()) {
            return $this->entryUrl($tenant).'/login';
        }

        return $this->addressing->absoluteOriginForHost($this->addressing->platformHost())
            .route('tenant.login', ['tenant' => $this->entryKey($tenant)], absolute: false);
    }

    public function loginPath(Tenant $tenant): string
    {
        if ($this->addressing->isHost()) {
            return '/login';
        }

        return route('tenant.login', ['tenant' => $this->entryKey($tenant)], absolute: false);
    }

    public function dashboardUrl(Tenant $tenant): string
    {
        if ($this->addressing->isHost()) {
            return $this->entryUrl($tenant).'/dashboard';
        }

        return $this->addressing->absoluteOriginForHost($this->addressing->platformHost())
            .route('dashboard', ['tenant' => $this->entryKey($tenant)], absolute: false);
    }

    public function dashboardPath(Tenant $tenant): string
    {
        if ($this->addressing->isHost()) {
            return '/dashboard';
        }

        return route('dashboard', ['tenant' => $this->entryKey($tenant)], absolute: false);
    }

    /**
     * Generate an absolute canonical URL for a named Tenant route in either profile.
     *
     * @param  array<string, mixed>  $parameters
     */
    public function namedRouteUrl(string $name, Tenant $tenant, array $parameters = []): string
    {
        $tenantParameter = $this->addressing->isHost()
            ? ['tenant_label' => $this->entryKey($tenant)]
            : ['tenant' => $this->entryKey($tenant)];

        $generated = route($name, array_merge($tenantParameter, $parameters), absolute: true);

        if ($this->addressing->isHost()) {
            $parts = parse_url($generated);
            $path = $parts['path'] ?? '/';
            $query = isset($parts['query']) ? '?'.$parts['query'] : '';

            return $this->entryUrl($tenant).$path.$query;
        }

        return $generated;
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

        if ($tenant === null) {
            $tenant = $this->tenantFromHostLabel($request);
        }

        if ($tenant !== null) {
            if ($this->isActiveForTenantLogin($tenant)) {
                $this->rememberIntended($request, $tenant);

                return $this->loginUrl($tenant);
            }

            // Inactive/suspended: do not send to central discovery; same path as visiting tenant login.
            return $this->loginUrl($tenant);
        }

        if ($this->addressing->isHost()) {
            $label = $this->hostLabelFromTenantCandidateRequest($request);
            if ($label !== null) {
                return $this->addressing->absoluteOriginForHost(
                    $this->addressing->tenantHostFqdn($label)
                ).'/login';
            }
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

        $canonicalEntry = parse_url($this->entryUrl($tenant));
        if ($canonicalEntry === false || ! isset($canonicalEntry['host'])) {
            return false;
        }

        if (isset($parts['scheme']) || isset($parts['host'])) {
            $scheme = $parts['scheme'] ?? ($canonicalEntry['scheme'] ?? 'https');
            $host = $parts['host'] ?? null;
            if ($host === null) {
                return false;
            }
            if (strcasecmp((string) $host, (string) $canonicalEntry['host']) !== 0) {
                return false;
            }
            $expectedScheme = $canonicalEntry['scheme'] ?? 'https';
            if (strcasecmp((string) $scheme, (string) $expectedScheme) !== 0) {
                return false;
            }
        } elseif ($this->addressing->isHost()) {
            // Relative URLs on Host profile are only safe when already on the tenant host.
            if (strcasecmp($request->getHost(), (string) $canonicalEntry['host']) !== 0) {
                return false;
            }
        }

        $path = $parts['path'] ?? '';
        if (! is_string($path) || $path === '') {
            return false;
        }

        if ($this->addressing->isHost()) {
            // Any path on the tenant origin is acceptable (same-host boundary).
            return true;
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
        if (tenancy()->initialized && tenancy()->tenant instanceof Tenant) {
            $class = \App\Http\Middleware\RequestHostClassifier::classOf($request);
            if ($class === \App\Http\Middleware\RequestHostClassifier::CLASS_TENANT_CANDIDATE) {
                return tenancy()->tenant;
            }
        }

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

    private function tenantFromHostLabel(Request $request): ?Tenant
    {
        $label = $this->hostLabelFromTenantCandidateRequest($request);
        if ($label === null) {
            return null;
        }

        return $this->findTenantByKey($label);
    }

    private function hostLabelFromTenantCandidateRequest(Request $request): ?string
    {
        if (! $this->addressing->isHost()) {
            return null;
        }

        $class = \App\Http\Middleware\RequestHostClassifier::classOf($request)
            ?? app(\App\Http\Middleware\RequestHostClassifier::class)->classify($request);

        if ($class !== \App\Http\Middleware\RequestHostClassifier::CLASS_TENANT_CANDIDATE) {
            return null;
        }

        $host = strtolower($request->getHost());
        $base = strtolower($this->addressing->platformBaseDomain());
        if ($base === '') {
            return null;
        }

        $suffix = '.'.$base;
        if (! str_ends_with($host, $suffix)) {
            return null;
        }

        $label = substr($host, 0, -strlen($suffix));
        if ($label === '' || str_contains($label, '.')) {
            return null;
        }

        return $label;
    }
}
