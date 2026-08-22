<?php

namespace Modules\Identity\Services;

use App\Support\Contracts\Audit\AuditLoggerInterface;
use Modules\Identity\Models\TenantUser;
use Modules\Identity\Support\Auth\AuthenticationAdministrationAssurance;
use Modules\Identity\Support\Auth\AuthenticationAdministrationGate;
use Modules\Identity\Support\Auth\AuthenticationLoginPolicy;
use Modules\Tenancy\Models\Tenant;

/**
 * WAVE-4/5: Admin Change Authentication Policy.
 * SSO-only requires WAVE-5 Enforcement Readiness Gate (via SecurityPolicyService).
 * Does not unlink SSO, delete Password, or alter Roles.
 */
class AuthenticationPolicyAdministrationService
{
    public function __construct(
        protected AuthenticationAdministrationGate $gate,
        protected SecurityPolicyService $policies,
        protected AuditLoggerInterface $audit,
    ) {}

    public function change(
        Tenant $tenant,
        TenantUser $actor,
        string $newPolicy,
    ): array {
        $mode = AuthenticationLoginPolicy::normalize($newPolicy);

        $this->gate->assertMayOperate(
            $tenant,
            $actor,
            AuthenticationAdministrationAssurance::OP_CHANGE_POLICY,
            null,
        );

        $before = $this->policies->getAuthenticationPolicy($tenant);
        // Gate runs inside SecurityPolicyService::update when transitioning to SSO.
        $this->policies->update($tenant, ['authentication_policy' => $mode]);
        $after = $this->policies->getAuthenticationPolicy($tenant);

        $this->audit->log('auth_admin.authentication_policy.changed', [
            'tenant_id' => (string) $tenant->id,
            'auditable_type' => TenantUser::class,
            'auditable_id' => (string) $actor->id,
            'old_values' => ['authentication_policy' => $before],
            'new_values' => [
                'authentication_policy' => $after,
                'actor_user_id' => (string) $actor->id,
            ],
        ]);

        return [
            'before' => $before,
            'after' => $after,
        ];
    }
}
