<?php

namespace Modules\Identity\Support\Auth;

use Illuminate\Validation\ValidationException;
use Modules\Identity\Models\Membership;
use Modules\Identity\Models\TenantUser;
use Modules\Identity\Support\Sso\SsoSecurityAudit;
use Modules\Tenancy\Models\Tenant;

/**
 * WAVE-4 shared Authentication Administration security boundary.
 *
 * Admin → tenant scope → authorization → fresh Admin proof → operation.
 * Not a Generic Approval Engine.
 */
final class AuthenticationAdministrationGate
{
    public const PERMISSION = 'tenant.auth-admin.operate';

    public function __construct(
        protected AuthenticationAdministrationAssurance $assurance,
        protected SsoSecurityAudit $audit,
    ) {}

    /**
     * @throws ValidationException
     */
    public function assertMayOperate(
        Tenant $tenant,
        TenantUser $actor,
        string $purpose,
        ?TenantUser $target = null,
        bool $consumeFreshness = true,
    ): void {
        if ((string) $actor->tenant_id !== (string) $tenant->id) {
            $this->deny($tenant, $actor, $purpose, 'actor_tenant_mismatch');
        }

        $membership = Membership::query()
            ->withoutGlobalScope('tenant')
            ->where('tenant_id', $tenant->id)
            ->where('user_id', $actor->id)
            ->where('status', 'active')
            ->first();

        if (! $membership) {
            $this->deny($tenant, $actor, $purpose, 'actor_not_active_member');
        }

        if (! $actor->can(self::PERMISSION) && ! $actor->can('tenant.security-policy.update')) {
            $this->deny($tenant, $actor, $purpose, 'missing_permission');
        }

        if ($target !== null) {
            if ((string) $target->tenant_id !== (string) $tenant->id) {
                $this->deny($tenant, $actor, $purpose, 'target_cross_tenant');
            }
        }

        if (! $this->assurance->isSatisfied($actor, $tenant, $purpose)) {
            $this->audit->record('auth_admin.freshness_denied', [
                'tenant_id' => (string) $tenant->id,
                'actor_user_id' => (string) $actor->id,
                'purpose' => $purpose,
                'target_user_id' => $target ? (string) $target->id : null,
            ]);

            throw ValidationException::withMessages([
                'freshness' => ['Fresh administrator authentication is required for this security operation.'],
            ]);
        }

        if ($consumeFreshness) {
            $this->assurance->consume($purpose);
        }

        $this->audit->record('auth_admin.authorized', [
            'tenant_id' => (string) $tenant->id,
            'actor_user_id' => (string) $actor->id,
            'purpose' => $purpose,
            'target_user_id' => $target ? (string) $target->id : null,
        ]);
    }

    protected function deny(Tenant $tenant, TenantUser $actor, string $purpose, string $reason): never
    {
        $this->audit->record('auth_admin.denied', [
            'tenant_id' => (string) $tenant->id,
            'actor_user_id' => (string) $actor->id,
            'purpose' => $purpose,
            'reason' => $reason,
        ]);

        abort(403, 'Not authorized for Authentication Administration.');
    }
}
