<?php

namespace Modules\Identity\Services;

use Modules\Identity\Models\Membership;
use Modules\Identity\Models\TenantUser;
use Modules\Identity\Models\TenantUserIdentity;
use Modules\Identity\Support\Auth\AuthenticationAdministrationGate;
use Modules\Identity\Support\Auth\SsoUserReadinessState;
use Modules\Identity\Support\Sso\SsoIdentityLifecycle;
use Modules\Tenancy\Models\Tenant;

/**
 * WAVE-5: Semantic last usable privileged Tenant Admin safety.
 * No universal numeric N-of-M threshold. Preserves membership last-owner guards separately.
 */
class LastUsablePrivilegedAdminGuard
{
    public function __construct(
        protected SsoReadinessAccountingService $accounting,
        protected SsoIdentityLifecycle $lifecycle,
        protected SsoConfigService $configService,
    ) {}

    /**
     * Under proposed SSO-only, at least one privileged Admin must remain able to authenticate
     * via SSO Ready (or valid Exception / temporary recovery is not required for gate — Ready preferred).
     *
     * Privileged = active membership + tenant.auth-admin.operate OR tenant.security-policy.update
     * OR membership_type owner (current repository semantics).
     */
    public function hasUsablePrivilegedAdminUnderSsoOnly(Tenant $tenant): bool
    {
        $wasInitialized = tenancy()->initialized;
        if (! $wasInitialized) {
            tenancy()->initialize($tenant);
        }

        try {
            $activeVersionId = $this->configService->getActiveVersionId($tenant);

            $memberships = Membership::query()
                ->withoutGlobalScope('tenant')
                ->where('tenant_id', $tenant->id)
                ->where('status', 'active')
                ->get();

            foreach ($memberships as $membership) {
                $user = TenantUser::query()
                    ->withoutGlobalScope('tenant')
                    ->where('tenant_id', $tenant->id)
                    ->whereKey($membership->user_id)
                    ->first();

                if (! $user || $user->trashed()) {
                    continue;
                }

                if (! $this->isPrivileged($tenant, $user, $membership)) {
                    continue;
                }

                $classified = $this->accounting->classifyUser($tenant, $user);
                if (in_array($classified['state'], [SsoUserReadinessState::READY, SsoUserReadinessState::EXCEPTION], true)) {
                    return true;
                }

                $current = TenantUserIdentity::query()
                    ->where('tenant_id', $tenant->id)
                    ->where('user_id', $user->id)
                    ->where('binding_role', TenantUserIdentity::ROLE_CURRENT)
                    ->first();

                if ($current instanceof TenantUserIdentity
                    && $this->lifecycle->isEffectivelyReady($current, $user, $activeVersionId)
                ) {
                    return true;
                }
            }

            return false;
        } finally {
            if (! $wasInitialized) {
                tenancy()->end();
            }
        }
    }

    protected function isPrivileged(Tenant $tenant, TenantUser $user, Membership $membership): bool
    {
        if ((string) ($membership->membership_type ?? '') === 'owner') {
            return true;
        }

        app(\Spatie\Permission\PermissionRegistrar::class)->setPermissionsTeamId($tenant->getTenantKey());
        try {
            return $user->can(AuthenticationAdministrationGate::PERMISSION)
                || $user->can('tenant.security-policy.update');
        } finally {
            app(\Spatie\Permission\PermissionRegistrar::class)->setPermissionsTeamId(null);
        }
    }
}
