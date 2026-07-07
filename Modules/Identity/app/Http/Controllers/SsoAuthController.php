<?php

namespace Modules\Identity\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Modules\Identity\Exceptions\SsoSecurityException;
use Modules\Identity\Services\SsoAuthService;
use Modules\Tenancy\Models\Tenant;

/**
 * BK-008: OIDC redirect/callback — sole owner of Auth::login() for federated sign-in.
 */
class SsoAuthController extends Controller
{
    public function __construct(
        protected SsoAuthService $ssoAuthService,
    ) {}

    public function redirect(Request $request, Tenant $tenant): RedirectResponse
    {
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

        Auth::guard('web')->login($result->user);
        $request->session()->regenerate();
        $request->session()->put('tenant_id', $tenant->id);

        return redirect()->intended('/t/'.$tenant->id.'/dashboard');
    }
}
