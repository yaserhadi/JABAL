<?php

namespace Modules\Identity\Http\Controllers;

use App\Http\Auth\TenantInertiaProps;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Identity\Models\TenantUser;
use Modules\Identity\Services\MfaService;
use Modules\Identity\Support\Sso\SsoFirstLinkAssurance;
use Modules\Tenancy\Models\Tenant;

/**
 * WAVE-1 GAP-008: Password then MFA step-up for SSO first-link. Does not log in or change AuthZ.
 */
class WorkforceSsoEnrollmentStepUpController extends Controller
{
    public function __construct(
        protected SsoFirstLinkAssurance $assurance,
        protected MfaService $mfaService,
        protected \App\Http\Auth\TenantEntryUrlResolver $urls,
    ) {}

    public function showPassword(Request $request): Response
    {
        return Inertia::render('Security/SsoEnrollment/StepUpPassword', [
            'tenant' => TenantInertiaProps::from($this->tenant()),
        ]);
    }

    public function confirmPassword(Request $request): RedirectResponse
    {
        $request->validate([
            'password' => ['required', 'string'],
        ]);

        $user = $request->user();
        if (! $user instanceof TenantUser || ! Hash::check((string) $request->input('password'), (string) $user->password)) {
            return back()->withErrors(['password' => 'The password is incorrect.']);
        }

        $this->assurance->markPasswordConfirmed();
        $request->session()->put(SsoFirstLinkAssurance::SESSION_MFA_INTENT, SsoFirstLinkAssurance::PURPOSE);

        $tenant = $this->tenant();

        if (! $this->mfaService->userHasConfirmedMfa($user)) {
            return redirect()->away($this->urls->namedRouteUrl('identity.mfa.enroll', $tenant));
        }

        return redirect()->away($this->urls->namedRouteUrl('identity.mfa.challenge', $tenant));
    }

    protected function tenant(): Tenant
    {
        $tenant = tenancy()->tenant;
        if (! $tenant instanceof Tenant) {
            abort(404);
        }

        return $tenant;
    }
}
