<?php

namespace Modules\Identity\Services;

use App\Support\Contracts\Audit\AuditLoggerInterface;
use Illuminate\Validation\ValidationException;
use Modules\Identity\Models\SsoIdentityResetTransaction;
use Modules\Identity\Models\TenantSecurityPolicy;
use Modules\Identity\Models\TenantUser;
use Modules\Identity\Support\Auth\AuthenticationAdministrationAssurance;
use Modules\Identity\Support\Auth\AuthenticationAdministrationGate;
use Modules\Identity\Support\Auth\AuthenticationLoginPolicy;
use Modules\Identity\Support\Sso\SsoFirstLinkAssurance;
use Modules\Tenancy\Models\Tenant;

/**
 * WAVE-4 GAP-014: IdP migration PATH A (direct step-up) and PATH B (explicit Password bridge).
 * Preserves User UUID / Roles / Membership. Not WAVE-5 population recovery.
 */
class IdpMigrationService
{
    public function __construct(
        protected AuthenticationAdministrationGate $gate,
        protected ResetSsoService $resetSso,
        protected SecurityPolicyService $policies,
        protected AuditLoggerInterface $audit,
    ) {}

    /**
     * PATH A: start Reset SSO migration using fresh Password+MFA step-up proof
     * without enabling ordinary Password LOGIN policy.
     *
     * @return array{transaction: SsoIdentityResetTransaction}
     */
    public function startPathA(Tenant $tenant, TenantUser $actor, TenantUser $target): array
    {
        $this->gate->assertMayOperate(
            $tenant,
            $actor,
            AuthenticationAdministrationAssurance::OP_IDP_MIGRATION,
            $target,
        );

        // Target user must present fresh local Password+MFA for PATH A (sensitive-op proof).
        // When Admin initiates for another User, Admin freshness already proven; target proves at association time.
        $result = $this->resetSso->initiate(
            $tenant,
            $actor,
            $target,
            compromisedCurrent: false,
            purpose: SsoIdentityResetTransaction::PURPOSE_IDP_MIGRATION_A,
            consumeFreshness: false,
            skipGate: true,
        );

        $this->audit->log('auth_admin.idp_migration.path_a.started', [
            'tenant_id' => (string) $tenant->id,
            'auditable_type' => SsoIdentityResetTransaction::class,
            'auditable_id' => (string) $result['transaction']->id,
            'new_values' => [
                'target_user_id' => (string) $target->id,
                'actor_user_id' => (string) $actor->id,
                'password_login_policy_unchanged' => true,
            ],
        ]);

        return $result;
    }

    /**
     * Assert target has fresh Password+MFA for PATH A association (not LOGIN policy).
     */
    public function assertPathATargetStepUp(TenantUser $target, Tenant $tenant): void
    {
        $assurance = app(SsoFirstLinkAssurance::class);
        if (! $assurance->isSatisfied($target, $tenant)) {
            throw ValidationException::withMessages([
                'step_up' => ['PATH A requires fresh Password+MFA step-up for the target user.'],
            ]);
        }
    }

    /**
     * PATH B: explicitly permit temporary Password LOGIN for migration scope (tenant policy → both).
     * Not automatic fallback after SSO failure.
     */
    public function activatePathBBridge(Tenant $tenant, TenantUser $actor): array
    {
        $this->gate->assertMayOperate(
            $tenant,
            $actor,
            AuthenticationAdministrationAssurance::OP_IDP_MIGRATION,
            null,
        );

        $before = $this->policies->getAuthenticationPolicy($tenant);
        // Explicit temporary bridge: ensure Password LOGIN is permitted alongside SSO.
        $this->policies->update($tenant, [
            'authentication_policy' => AuthenticationLoginPolicy::BOTH,
        ]);

        // Mark bridge active on policy row via dedicated flag if column absent — use audit + config note.
        // Store bridge marker in a JSON-friendly way: reuse password_policy meta is wrong.
        // Use session-less durable marker: tenant_security_policies has no bridge column yet —
        // add lightweight audit-only + optional app setting. For WAVE-4: security policy update is the explicit act.

        $this->audit->log('auth_admin.idp_migration.path_b.bridge_activated', [
            'tenant_id' => (string) $tenant->id,
            'auditable_type' => TenantSecurityPolicy::class,
            'auditable_id' => (string) $tenant->id,
            'old_values' => ['authentication_policy' => $before],
            'new_values' => [
                'authentication_policy' => AuthenticationLoginPolicy::BOTH,
                'actor_user_id' => (string) $actor->id,
                'explicit' => true,
            ],
        ]);

        $reset = $this->resetSso->initiate(
            $tenant,
            $actor,
            $actor,
            compromisedCurrent: false,
            purpose: SsoIdentityResetTransaction::PURPOSE_IDP_MIGRATION_B,
            consumeFreshness: false,
            skipGate: true,
        );

        return [
            'policy_before' => $before,
            'policy_after' => AuthenticationLoginPolicy::BOTH,
            'transaction' => $reset['transaction'],
        ];
    }

    public function deactivatePathBBridge(
        Tenant $tenant,
        TenantUser $actor,
        string $finalPolicy,
    ): array {
        $this->gate->assertMayOperate(
            $tenant,
            $actor,
            AuthenticationAdministrationAssurance::OP_IDP_MIGRATION,
            null,
        );

        $mode = AuthenticationLoginPolicy::normalize($finalPolicy);
        $before = $this->policies->getAuthenticationPolicy($tenant);
        $this->policies->update($tenant, ['authentication_policy' => $mode]);

        $this->audit->log('auth_admin.idp_migration.path_b.bridge_deactivated', [
            'tenant_id' => (string) $tenant->id,
            'auditable_type' => TenantSecurityPolicy::class,
            'auditable_id' => (string) $tenant->id,
            'old_values' => ['authentication_policy' => $before],
            'new_values' => [
                'authentication_policy' => $mode,
                'actor_user_id' => (string) $actor->id,
            ],
        ]);

        return ['before' => $before, 'after' => $mode];
    }
}
