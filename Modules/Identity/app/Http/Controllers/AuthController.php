<?php

namespace Modules\Identity\Http\Controllers;

use App\Http\Auth\TenantEntryUrlResolver;
use App\Http\Auth\TenantInertiaProps;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Modules\Identity\Events\UserRegistered;
use Modules\Identity\Models\TenantUser;
use Modules\Identity\Services\SsoOperationalExposureService;
use Modules\Identity\Services\TenantLoginDiscoveryService;
use Modules\Identity\Services\TenantRegistrationService;
use Modules\Tenancy\Events\TenantCreated;
use Modules\Tenancy\Models\Tenant;

class AuthController extends Controller
{
    public function showLogin()
    {
        return Inertia::render('Auth/Login');
    }

    public function showTenantLogin(?Tenant $tenant = null)
    {
        $tenant = $this->resolveTenantArgument($tenant);

        if ($tenant->status !== 'active') {
            abort(404);
        }

        $actorUserId = request()->user()?->getAuthIdentifier();
        $actorUserId = is_string($actorUserId) ? $actorUserId : null;

        $exposure = app(SsoOperationalExposureService::class);
        $ssoOperational = $exposure->isExposedOnTenantLogin($tenant, $actorUserId);
        $ssoStartUrl = $ssoOperational ? $exposure->startUrlForTenantLogin($tenant) : null;
        $loginPolicy = app(\Modules\Identity\Support\Auth\AuthenticationLoginPolicy::class);
        // WAVE-5: under SSO-only, form remains available for Exception / temporary recovery LOGIN only.
        $passwordLoginAllowed = $loginPolicy->allowsPasswordLogin($tenant)
            || $loginPolicy->mode($tenant) === \Modules\Identity\Support\Auth\AuthenticationLoginPolicy::SSO;

        return Inertia::render('Auth/TenantLogin', [
            'tenant' => TenantInertiaProps::from($tenant),
            'ssoOperational' => $ssoOperational,
            'ssoStartUrl' => $ssoStartUrl,
            'passwordLoginAllowed' => $passwordLoginAllowed,
            'prefillEmail' => old('email', request()->query('email')),
        ]);
    }

    /**
     * BK-064: Central POST /login is discovery/routing only — no TenantUser authentication.
     */
    public function login(Request $request, TenantLoginDiscoveryService $discovery)
    {
        $validated = $request->validate([
            'slug' => 'nullable|string|max:255',
            'email' => 'nullable|email',
        ]);

        $tenant = $discovery->resolveActiveTenant(
            $validated['slug'] ?? null,
            $validated['email'] ?? null,
        );

        $query = [];
        if (! empty($validated['email'])) {
            $query['email'] = $validated['email'];
        }

        $url = app(TenantEntryUrlResolver::class)->loginUrl($tenant);
        if ($query !== []) {
            $url .= (str_contains($url, '?') ? '&' : '?').http_build_query($query);
        }

        if ($request->header('X-Inertia')) {
            return Inertia::location($url);
        }

        return redirect()->to($url);
    }

    /**
     * Tenant-local password authentication after Tenant is resolved.
     */
    public function tenantLogin(Request $request, ?Tenant $tenant = null)
    {
        $tenant = $this->resolveTenantArgument($tenant);

        if ($tenant->status !== 'active') {
            abort(404);
        }

        $request->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);

        tenancy()->initialize($tenant);

        $tenantUser = TenantUser::findForLogin($request->input('email'));

        if (! $tenantUser || $tenantUser->tenant_id !== $tenant->id) {
            tenancy()->end();
            throw ValidationException::withMessages([
                'email' => __('The provided credentials do not match our records.'),
            ]);
        }

        // WAVE-5: evaluate Password LOGIN after identifying User (Exception / temporary recovery).
        try {
            app(\Modules\Identity\Support\Auth\AuthenticationLoginPolicy::class)
                ->assertPasswordLoginAllowed($tenant, $tenantUser);
        } catch (ValidationException $e) {
            tenancy()->end();
            throw $e;
        }

        if (! Auth::guard('web')->attempt(
            $request->only('email', 'password'),
            $request->boolean('remember')
        )) {
            tenancy()->end();
            throw ValidationException::withMessages([
                'email' => __('The provided credentials do not match our records.'),
            ]);
        }

        $request->session()->regenerate();
        $request->session()->put('tenant_id', $tenant->id);

        // BK-099: honor opaque enrollment login resume after local auth.
        $resumeService = app(\Modules\Identity\Services\WorkforceSsoEnrollmentLoginResumeService::class);
        $resumeToken = $resumeService->peekTokenFromRequest($request);
        if ($resumeToken !== '') {
            $binding = (string) $request->cookie(
                \Modules\Identity\Support\Sso\SsoBrowserBindingCookieFactory::ENROLLMENT_BROWSER_BINDING,
                ''
            );
            if ($binding !== '') {
                return redirect()->to($resumeService->resumeUrl($tenant, $resumeToken));
            }
        }

        return app(TenantEntryUrlResolver::class)->redirectAfterLogin($request, $tenant);
    }

    public function showRegister()
    {
        return Inertia::render('Auth/Register');
    }

    public function register(Request $request, TenantRegistrationService $registration)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255',
            'password' => 'required|string|min:8|confirmed',
        ]);

        $tenantUser = $registration->registerTenantUser(
            $validated['name'],
            $validated['email'],
            $validated['password']
        );

        $tenant = $tenantUser->homeTenant();

        event(new UserRegistered($tenantUser));
        if ($tenant) {
            event(new TenantCreated($tenant));
        }

        tenancy()->initialize($tenant);
        Auth::guard('web')->login($tenantUser);
        $request->session()->regenerate();
        $request->session()->put('tenant_id', $tenant->id);

        $target = app(TenantEntryUrlResolver::class)->dashboardUrl($tenant);

        if ($request->header('X-Inertia')) {
            return Inertia::location($target);
        }

        return redirect($target);
    }

    public function logout(Request $request)
    {
        $resolver = app(TenantEntryUrlResolver::class);
        $tip = $resolver->resolveTenantForRedirect($request);
        $resolver->clearIntended($request);

        $tenantId = $tip instanceof Tenant ? (string) $tip->id : null;
        if ($tenantId === null && tenancy()->initialized && tenancy()->tenant instanceof Tenant) {
            $tenantId = (string) tenancy()->tenant->id;
        }

        // Clear Tenant-local SSO / MFA transient state before session invalidate.
        \Modules\Identity\Support\Sso\SsoMfaContinuation::clear($request->session());
        $request->session()->forget([
            'mfa_verified_at',
            'tenant_id',
            \Modules\Identity\Support\Sso\SsoMfaContinuation::DEFER_USER_SESSION_KEY,
        ]);

        Auth::guard('web')->logout();
        if (tenancy()->initialized) {
            tenancy()->end();
        }
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if (is_string($tenantId) && $tenantId !== '') {
            app(\Modules\Identity\Support\Sso\SsoSecurityAudit::class)->record('sso.logout.local', [
                'tenant_id' => $tenantId,
                'reason' => 'tenant_local_logout',
            ]);
        }

        $secure = $request->isSecure();
        $response = redirect()->to(
            $tip instanceof Tenant
                ? $resolver->loginUrl($tip)
                : $resolver->guestRedirectUrl($request)
        );

        return $response
            ->withCookie(\Modules\Identity\Support\Sso\SsoBrowserBindingCookieFactory::clear(
                \Modules\Identity\Support\Sso\SsoBrowserBindingCookieFactory::TENANT_CONTINUATION,
                $secure,
            ))
            ->withCookie(\Modules\Identity\Support\Sso\SsoBrowserBindingCookieFactory::clear(
                \Modules\Identity\Support\Sso\SsoBrowserBindingCookieFactory::AUTH_BINDING,
                $secure,
            ));
    }

    /**
     * Host routes omit {tenant}; resolve from Stancl context. Path routes keep model binding.
     */
    private function resolveTenantArgument(?Tenant $tenant): Tenant
    {
        if ($tenant instanceof Tenant) {
            return $tenant;
        }

        $resolved = tenancy()->tenant;
        if ($resolved instanceof Tenant) {
            return $resolved;
        }

        abort(404);
    }
}
