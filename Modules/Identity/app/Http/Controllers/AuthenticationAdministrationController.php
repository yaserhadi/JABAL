<?php

namespace Modules\Identity\Http\Controllers;

use App\Http\Auth\TenantInertiaProps;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Modules\Identity\Models\TenantUser;
use Modules\Identity\Services\AdminMfaResetService;
use Modules\Identity\Services\AdminPasswordResetService;
use Modules\Identity\Services\AuthenticationPolicyAdministrationService;
use Modules\Identity\Services\CanonicalEmailChangeService;
use Modules\Identity\Services\IdpMigrationService;
use Modules\Identity\Services\ResetSsoService;
use Modules\Identity\Support\Auth\AuthenticationAdministrationAssurance;

/**
 * WAVE-4: Authentication Administration operations (distinct; not Reset User).
 */
class AuthenticationAdministrationController extends Controller
{
    public function __construct(
        protected AuthenticationAdministrationAssurance $assurance,
        protected AdminPasswordResetService $passwordReset,
        protected AdminMfaResetService $mfaReset,
        protected ResetSsoService $resetSso,
        protected AuthenticationPolicyAdministrationService $policyAdmin,
        protected CanonicalEmailChangeService $emailChange,
        protected IdpMigrationService $idpMigration,
    ) {}

    public function index(Request $request): InertiaResponse
    {
        $tenant = tenancy()->tenant;
        if (! $tenant) {
            abort(404);
        }

        return Inertia::render('Security/AuthAdmin/Index', [
            'tenant' => TenantInertiaProps::from($tenant),
            'operations' => [
                'reset_password',
                'reset_mfa',
                'reset_sso',
                'change_policy',
                'change_email',
                'idp_migration_a',
                'idp_migration_b',
            ],
        ]);
    }

    public function confirmPassword(Request $request): RedirectResponse
    {
        $tenant = tenancy()->tenant;
        if (! $tenant) {
            abort(404);
        }

        $validated = $request->validate([
            'password' => ['required', 'string'],
            'purpose' => ['required', 'string', 'max:64'],
        ]);

        if (! \Illuminate\Support\Facades\Hash::check($validated['password'], $request->user()->password)) {
            throw ValidationException::withMessages([
                'password' => ['The password is incorrect.'],
            ]);
        }

        $this->assurance->markPasswordConfirmed($validated['purpose']);

        return back()->with('success', 'Administrator password confirmed. Complete MFA if required.');
    }

    public function resetPassword(Request $request): RedirectResponse
    {
        $tenant = tenancy()->tenant;
        $validated = $request->validate(['user_id' => ['required', 'uuid']]);
        $target = $this->findTarget($tenant->id, $validated['user_id']);
        $this->passwordReset->initiate($tenant, $request->user(), $target);

        return back()->with('success', 'Password reset initiated. The user must set their own new password.');
    }

    public function resetMfa(Request $request): RedirectResponse
    {
        $tenant = tenancy()->tenant;
        $validated = $request->validate(['user_id' => ['required', 'uuid']]);
        $target = $this->findTarget($tenant->id, $validated['user_id']);
        $this->mfaReset->reset($tenant, $request->user(), $target);

        return back()->with('success', 'MFA enrollment reset for the user.');
    }

    public function resetSso(Request $request): RedirectResponse
    {
        $tenant = tenancy()->tenant;
        $validated = $request->validate([
            'user_id' => ['required', 'uuid'],
            'compromised' => ['sometimes', 'boolean'],
        ]);
        $target = $this->findTarget($tenant->id, $validated['user_id']);
        $this->resetSso->initiate(
            $tenant,
            $request->user(),
            $target,
            compromisedCurrent: (bool) ($validated['compromised'] ?? false),
        );

        return back()->with('success', 'Reset SSO initiated. Candidate must complete Linked → ordinary login → Ready.');
    }

    public function changePolicy(Request $request): RedirectResponse
    {
        $tenant = tenancy()->tenant;
        $validated = $request->validate([
            'authentication_policy' => ['required', 'string', 'in:password,sso,both'],
        ]);
        $this->policyAdmin->change($tenant, $request->user(), $validated['authentication_policy']);

        return back()->with('success', 'Authentication policy updated.');
    }

    public function changeEmail(Request $request): RedirectResponse
    {
        $tenant = tenancy()->tenant;
        $validated = $request->validate([
            'user_id' => ['required', 'uuid'],
            'email' => ['required', 'email', 'max:255'],
        ]);
        $target = $this->findTarget($tenant->id, $validated['user_id']);
        $result = $this->emailChange->initiate($tenant, $request->user(), $target, $validated['email']);

        return back()->with([
            'success' => 'Email change initiated. Mailbox verification required.',
            'emailChangeVerifyUrl' => $result['verify_url'] ?? null,
        ]);
    }

    public function startPathA(Request $request): RedirectResponse
    {
        $tenant = tenancy()->tenant;
        $validated = $request->validate(['user_id' => ['required', 'uuid']]);
        $target = $this->findTarget($tenant->id, $validated['user_id']);
        $this->idpMigration->startPathA($tenant, $request->user(), $target);

        return back()->with('success', 'IdP migration PATH A started (Reset SSO).');
    }

    public function activatePathB(Request $request): RedirectResponse
    {
        $tenant = tenancy()->tenant;
        $this->idpMigration->activatePathBBridge($tenant, $request->user());

        return back()->with('success', 'PATH B Password+MFA bridge activated explicitly.');
    }

    public function verifyEmailChange(string $token): RedirectResponse
    {
        $this->emailChange->verifyMailbox($token);

        return redirect()->route('login')->with('success', 'Email verified. An administrator can complete the change.');
    }

    protected function findTarget(string $tenantId, string $userId): TenantUser
    {
        $target = TenantUser::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->whereKey($userId)
            ->first();

        if (! $target) {
            abort(404);
        }

        return $target;
    }
}
