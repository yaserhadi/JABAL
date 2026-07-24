<?php

namespace Modules\Identity\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Modules\Identity\Exceptions\SsoSecurityException;
use Modules\Identity\Models\TenantUser;
use Modules\Identity\Services\SsoAuthService;
use Modules\Tenancy\Models\Tenant;

/**
 * Path OIDC redirect/callback — Path federated login authority (with Host handoff).
 *
 * BK-097: existing-link-only resolution via service; D12 ordinary session gates before login.
 */
class SsoAuthController extends Controller
{
    public function __construct(
        protected SsoAuthService $ssoAuthService,
    ) {}

    public function redirect(Request $request, ?Tenant $tenant = null): RedirectResponse
    {
        // Defense-in-depth: Path-era SSO start is Path-profile only (not registered on Host).
        if (app(\App\Support\Tenancy\TenantAddressingProfile::class)->isHost()) {
            abort(404);
        }

        $tenant = $tenant ?? (tenancy()->tenant instanceof Tenant ? tenancy()->tenant : null);
        if (! $tenant instanceof Tenant) {
            abort(404);
        }

        try {
            $this->ssoAuthService->assertTenantMayStartSso($tenant);

            return redirect()->away($this->ssoAuthService->buildAuthorizationRedirectUrl($tenant));
        } catch (SsoSecurityException) {
            return redirect()
                ->route('login')
                ->withErrors(['email' => __('Single sign-on is not available for this organization.')]);
        }
    }

    public function callback(Request $request): RedirectResponse
    {
        // Defense-in-depth: Path-era SSO callback is Path-profile only (not registered on Host).
        if (app(\App\Support\Tenancy\TenantAddressingProfile::class)->isHost()) {
            abort(404);
        }

        $tenant = tenancy()->tenant;

        if (! $tenant instanceof Tenant) {
            abort(403, 'SSO callback requires tenant context.');
        }

        try {
            $result = $this->ssoAuthService->completeCallback($tenant, [
                'code' => $request->query('code'),
                'state' => $request->query('state'),
            ]);
        } catch (SsoSecurityException) {
            return redirect()
                ->route('login')
                ->withErrors(['email' => __('Unable to sign in with single sign-on.')]);
        }

        if (! $result->succeeded() || $result->user === null) {
            return redirect()
                ->route('login')
                ->withErrors(['email' => __('Unable to sign in with single sign-on.')]);
        }

        $user = $result->user;
        $dashboard = app(\App\Http\Auth\TenantEntryUrlResolver::class)->dashboardUrl($tenant);

        if (Auth::guard('web')->check()) {
            if (! $this->isSameUserSameTenantContinuation($user, $tenant)) {
                return redirect()
                    ->route('login')
                    ->withErrors(['email' => __('Unable to sign in with single sign-on.')]);
            }

            // Ordinary same-user continuation: no login, logout, regenerate, or assurance upgrade.
            return redirect()->intended($dashboard);
        }

        Auth::guard('web')->login($user);
        $request->session()->regenerate();
        $request->session()->put('tenant_id', $tenant->id);

        return redirect()->intended($dashboard);
    }

    /**
     * Fail-closed same-user / same-tenant comparison (BK-097 / D12 ordinary).
     */
    protected function isSameUserSameTenantContinuation(TenantUser $resolvedUser, Tenant $tenant): bool
    {
        $current = Auth::guard('web')->user();
        if (! $current instanceof TenantUser) {
            return false;
        }

        if ((string) Auth::guard('web')->id() !== (string) $resolvedUser->id) {
            return false;
        }

        if ((string) $tenant->id !== (string) (tenancy()->tenant instanceof Tenant ? tenancy()->tenant->id : '')) {
            return false;
        }

        if ((string) $current->tenant_id !== (string) $tenant->id) {
            return false;
        }

        if ((string) $resolvedUser->tenant_id !== (string) $tenant->id) {
            return false;
        }

        $sessionTenantId = session('tenant_id');
        if ($sessionTenantId === null || (string) $sessionTenantId !== (string) $tenant->id) {
            return false;
        }

        return true;
    }
}
