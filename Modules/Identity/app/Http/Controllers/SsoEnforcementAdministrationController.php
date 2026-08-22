<?php

namespace Modules\Identity\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Identity\Models\SsoEnforcementException;
use Modules\Identity\Models\TenantUser;
use Modules\Identity\Services\SsoEnforcementExceptionService;
use Modules\Identity\Services\SsoEnforcementReadinessGate;
use Modules\Identity\Services\SsoReadinessAccountingService;
use Modules\Identity\Services\SecurityPolicyService;
use Modules\Identity\Support\Auth\AuthenticationAdministrationAssurance;
use Modules\Identity\Support\Auth\AuthenticationAdministrationGate;

/**
 * WAVE-5: Tenant SSO Enforcement administration (readiness, exceptions, mandatory enrollment flag).
 */
class SsoEnforcementAdministrationController extends Controller
{
    public function __construct(
        protected SsoReadinessAccountingService $accounting,
        protected SsoEnforcementReadinessGate $gate,
        protected SsoEnforcementExceptionService $exceptions,
        protected SecurityPolicyService $policies,
        protected AuthenticationAdministrationAssurance $assurance,
    ) {}

    public function index(Request $request): Response
    {
        $tenant = tenancy()->tenant;
        abort_unless($tenant, 404);

        $evaluation = $this->gate->evaluate($tenant);
        $summary = $this->accounting->summarizePopulation($tenant);
        $policy = $this->policies->getForTenant($tenant);

        $activeExceptions = SsoEnforcementException::query()
            ->where('tenant_id', $tenant->id)
            ->where('status', SsoEnforcementException::STATUS_ACTIVE)
            ->orderByDesc('created_at')
            ->limit(100)
            ->get(['id', 'user_id', 'reason', 'closure_mode', 'expires_at', 'created_at']);

        return Inertia::render('Security/SsoEnforcement/Index', [
            'counts' => $evaluation['counts'],
            'gatePass' => $evaluation['pass'],
            'gateFailures' => $evaluation['failures'],
            'population' => $summary,
            'exceptions' => $activeExceptions,
            'mandatorySsoEnrollment' => (bool) ($policy['mandatory_sso_enrollment'] ?? false),
            'exceptionClosureMode' => $policy['sso_exception_closure_mode'] ?? 'automatic',
            'authenticationPolicy' => $policy['authentication_policy'] ?? 'both',
        ]);
    }

    public function updateSettings(Request $request): RedirectResponse
    {
        $tenant = tenancy()->tenant;
        abort_unless($tenant, 404);
        /** @var TenantUser $actor */
        $actor = $request->user();

        $validated = $request->validate([
            'mandatory_sso_enrollment' => ['sometimes', 'boolean'],
            'sso_exception_closure_mode' => ['sometimes', 'string', 'in:automatic,manual'],
        ]);

        app(AuthenticationAdministrationGate::class)->assertMayOperate(
            $tenant,
            $actor,
            AuthenticationAdministrationAssurance::OP_CHANGE_POLICY,
            null,
        );

        $this->policies->update($tenant, $validated);

        return back()->with('success', 'SSO enforcement settings updated.');
    }

    public function storeException(Request $request): RedirectResponse
    {
        $tenant = tenancy()->tenant;
        abort_unless($tenant, 404);
        /** @var TenantUser $actor */
        $actor = $request->user();

        $validated = $request->validate([
            'user_id' => ['required', 'uuid'],
            'reason' => ['required', 'string', 'max:512'],
            'expires_at' => ['nullable', 'date'],
        ]);

        $target = TenantUser::query()
            ->withoutGlobalScope('tenant')
            ->where('tenant_id', $tenant->id)
            ->whereKey($validated['user_id'])
            ->firstOrFail();

        $this->exceptions->create(
            $tenant,
            $actor,
            $target,
            $validated['reason'],
            isset($validated['expires_at']) ? new \DateTimeImmutable($validated['expires_at']) : null,
        );

        return back()->with('success', 'Enforcement exception created.');
    }

    public function revokeException(Request $request, string $exceptionId): RedirectResponse
    {
        $tenant = tenancy()->tenant;
        abort_unless($tenant, 404);
        /** @var TenantUser $actor */
        $actor = $request->user();

        $exception = SsoEnforcementException::query()
            ->where('tenant_id', $tenant->id)
            ->whereKey($exceptionId)
            ->firstOrFail();

        $this->exceptions->revoke($tenant, $actor, $exception);

        return back()->with('success', 'Enforcement exception revoked.');
    }
}
