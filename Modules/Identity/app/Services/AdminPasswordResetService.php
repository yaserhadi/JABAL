<?php

namespace Modules\Identity\Services;

use App\Support\Contracts\Audit\AuditLoggerInterface;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;
use Modules\Identity\Models\TenantUser;
use Modules\Identity\Support\Auth\AuthenticationAdministrationAssurance;
use Modules\Identity\Support\Auth\AuthenticationAdministrationGate;
use Modules\Tenancy\Models\Tenant;

/**
 * WAVE-4 GAP-010: Admin initiates Password re-establishment.
 * Admin must not learn/set the final permanent User Password.
 */
class AdminPasswordResetService
{
    public function __construct(
        protected AuthenticationAdministrationGate $gate,
        protected SessionRegistryService $sessions,
        protected AuditLoggerInterface $audit,
    ) {}

    /**
     * @return array{status: string, user_id: string}
     */
    public function initiate(Tenant $tenant, TenantUser $actor, TenantUser $target): array
    {
        $this->gate->assertMayOperate(
            $tenant,
            $actor,
            AuthenticationAdministrationAssurance::OP_RESET_PASSWORD,
            $target,
        );

        $wasInitialized = tenancy()->initialized;
        if (! $wasInitialized) {
            tenancy()->initialize($tenant);
        }

        try {
            $status = Password::broker('tenant_users')->sendResetLink([
                'email' => $target->email,
            ]);

            if ($status !== Password::RESET_LINK_SENT) {
                throw ValidationException::withMessages([
                    'email' => ['Unable to initiate password reset for this user.'],
                ]);
            }

            // Local sessions for the target should not survive admin-initiated re-establishment.
            $this->sessions->revokeAllForUser($target);

            $this->audit->log('auth_admin.reset_password.initiated', [
                'tenant_id' => (string) $tenant->id,
                'auditable_type' => TenantUser::class,
                'auditable_id' => (string) $target->id,
                'new_values' => [
                    'actor_user_id' => (string) $actor->id,
                    'target_user_id' => (string) $target->id,
                ],
            ]);

            return [
                'status' => 'initiated',
                'user_id' => (string) $target->id,
            ];
        } finally {
            if (! $wasInitialized) {
                tenancy()->end();
            }
        }
    }
}
