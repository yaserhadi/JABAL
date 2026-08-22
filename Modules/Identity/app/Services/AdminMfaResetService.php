<?php

namespace Modules\Identity\Services;

use App\Support\Contracts\Audit\AuditLoggerInterface;
use Modules\Identity\Models\TenantUser;
use Modules\Identity\Support\Auth\AuthenticationAdministrationAssurance;
use Modules\Identity\Support\Auth\AuthenticationAdministrationGate;
use Modules\Identity\Support\MfaVerificationContext;
use Modules\Tenancy\Models\Tenant;

/**
 * WAVE-4 GAP-010: Admin Reset MFA — does not reset Password or unlink SSO.
 */
class AdminMfaResetService
{
    public function __construct(
        protected AuthenticationAdministrationGate $gate,
        protected MfaService $mfa,
        protected AuditLoggerInterface $audit,
    ) {}

    public function reset(Tenant $tenant, TenantUser $actor, TenantUser $target): void
    {
        $this->gate->assertMayOperate(
            $tenant,
            $actor,
            AuthenticationAdministrationAssurance::OP_RESET_MFA,
            $target,
        );

        $wasInitialized = tenancy()->initialized;
        if (! $wasInitialized) {
            tenancy()->initialize($tenant);
        }

        try {
            $this->mfa->revokeEnrollmentRecords($target);

            if ((string) $actor->id === (string) $target->id) {
                session()->forget('mfa_verified_at');
                MfaVerificationContext::clear();
            }

            $this->audit->log('auth_admin.reset_mfa.completed', [
                'tenant_id' => (string) $tenant->id,
                'auditable_type' => TenantUser::class,
                'auditable_id' => (string) $target->id,
                'new_values' => [
                    'actor_user_id' => (string) $actor->id,
                    'target_user_id' => (string) $target->id,
                ],
            ]);
        } finally {
            if (! $wasInitialized) {
                tenancy()->end();
            }
        }
    }
}
