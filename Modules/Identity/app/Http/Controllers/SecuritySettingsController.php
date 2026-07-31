<?php

namespace Modules\Identity\Http\Controllers;

use App\Http\Auth\TenantEntryUrlResolver;
use App\Http\Auth\TenantInertiaProps;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Modules\Identity\Http\Requests\UpdateSecurityPolicyRequest;
use Modules\Identity\Models\TenantUser;
use Modules\Identity\Services\ApiTokenService;
use Modules\Identity\Services\MfaService;
use Modules\Identity\Services\SecurityPolicyService;
use Modules\Identity\Services\SessionRegistryService;
use Modules\Identity\Services\SsoConfigService;
use Spatie\Permission\PermissionRegistrar;

/**
 * BK-035: Tenant security settings UI hub (Inertia).
 */
class SecuritySettingsController extends Controller
{
    public function __construct(
        protected SecurityPolicyService $securityPolicyService,
        protected SessionRegistryService $sessionRegistryService,
        protected MfaService $mfaService,
        protected ApiTokenService $apiTokenService,
        protected TenantEntryUrlResolver $tenantEntryUrls,
    ) {}

    public function show(Request $request): InertiaResponse
    {
        $tenant = tenancy()->tenant;
        if (! $tenant) {
            abort(404);
        }

        /** @var TenantUser $user */
        $user = $request->user();
        $currentLaravelSessionId = $request->session()->getId();

        $policies = $this->withTenantPermissions($tenant, function () use ($tenant, $user) {
            if (! $user->can('tenant.security-policy.view')) {
                return null;
            }

            return $this->securityPolicyService->getForTenant($tenant);
        });

        $sessions = $this->sessionRegistryService
            ->listForCurrentTenantUser($user, $tenant)
            ->map(fn ($session) => [
                'id' => $session->id,
                'device_label' => $session->device_label,
                'ip_address' => $session->ip_address,
                'last_activity_at' => $session->last_activity_at?->toIso8601String(),
                'logged_in_at' => $session->logged_in_at?->toIso8601String(),
                'is_current' => $session->session_id === $currentLaravelSessionId,
            ])
            ->values()
            ->all();

        $mfa = [
            'available' => $this->mfaService->isMfaAvailable($tenant),
            'required' => $this->mfaService->isMfaRequired($tenant),
            'enrolled' => $this->mfaService->userHasConfirmedMfa($user),
        ];

        $tokens = collect($this->apiTokenService->formatTokenList(
            $this->apiTokenService->listTokensForTenant($user, $tenant->id)
        ))
            ->map(fn (array $token) => Arr::only($token, ['id', 'name', 'created_at', 'last_used_at', 'expires_at']))
            ->values()
            ->all();

        $sso = $this->withTenantPermissions($tenant, function () use ($tenant, $user) {
            if (! $user->can('tenant.sso.view')) {
                return null;
            }

            return app(SsoConfigService::class)->getForTenant($tenant);
        });

        return Inertia::render('SecuritySettings/Index', [
            'tenant' => TenantInertiaProps::from($tenant),
            'policies' => $policies,
            'sessions' => $sessions,
            'mfa' => $mfa,
            'tokens' => $tokens,
            'sso' => $sso,
        ]);
    }

    public function updatePolicies(UpdateSecurityPolicyRequest $request): RedirectResponse
    {
        $tenant = tenancy()->tenant;
        if (! $tenant) {
            abort(404);
        }

        $this->securityPolicyService->update($tenant, $request->validated());

        return redirect()
            ->to($this->tenantEntryUrls->namedRouteUrl('identity.security-settings.show', $tenant))
            ->with('success', 'Security policies updated.');
    }

    public function revokeSession(Request $request): RedirectResponse
    {
        $tenantModel = tenancy()->tenant;
        if (! $tenantModel) {
            abort(404);
        }

        $session = (string) $request->route('session');
        if ($session === '') {
            abort(404);
        }

        /** @var TenantUser $user */
        $user = $request->user();
        $this->sessionRegistryService->revokeForCurrentTenantUser($user, $tenantModel, $session);

        return redirect()
            ->to($this->tenantEntryUrls->namedRouteUrl('identity.security-settings.show', $tenantModel))
            ->with('success', 'Session revoked.');
    }

    public function revokeOtherSessions(Request $request): RedirectResponse
    {
        $tenant = tenancy()->tenant;
        if (! $tenant) {
            abort(404);
        }

        /** @var TenantUser $user */
        $user = $request->user();
        $count = $this->sessionRegistryService->revokeOtherSessionsForCurrentTenantUser(
            $user,
            $tenant,
            $request->session()->getId()
        );

        return redirect()
            ->to($this->tenantEntryUrls->namedRouteUrl('identity.security-settings.show', $tenant))
            ->with('success', $count > 0 ? "Revoked {$count} other session(s)." : 'No other sessions to revoke.');
    }

    /**
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    protected function withTenantPermissions(\Modules\Tenancy\Models\Tenant $tenant, callable $callback): mixed
    {
        $registrar = app(PermissionRegistrar::class);
        $previousTeamId = $registrar->getPermissionsTeamId();
        $registrar->setPermissionsTeamId($tenant->getTenantKey());

        try {
            return $callback();
        } finally {
            $registrar->setPermissionsTeamId($previousTeamId);
        }
    }
}
