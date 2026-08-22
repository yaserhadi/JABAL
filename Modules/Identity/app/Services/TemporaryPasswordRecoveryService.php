<?php

namespace Modules\Identity\Services;

use App\Support\Contracts\Audit\AuditLoggerInterface;
use Illuminate\Validation\ValidationException;
use Modules\Identity\Models\TemporaryPasswordRecovery;
use Modules\Identity\Models\TenantUser;
use Modules\Tenancy\Models\Tenant;

/**
 * WAVE-5: Explicit temporary Password LOGIN recovery (≠ auto fallback, ≠ Reset User).
 */
class TemporaryPasswordRecoveryService
{
    public function __construct(
        protected AuditLoggerInterface $audit,
        protected SessionRegistryService $sessions,
    ) {}

    public function activate(
        Tenant $tenant,
        TenantUser $target,
        string $reason,
        string $classification,
        string $createdByType,
        ?string $createdById,
        ?string $peaCaseId,
        int $ttlHours = 24,
    ): TemporaryPasswordRecovery {
        if (! in_array($classification, [
            TemporaryPasswordRecovery::CLASS_AVAILABILITY,
            TemporaryPasswordRecovery::CLASS_COMPROMISE,
        ], true)) {
            throw ValidationException::withMessages([
                'classification' => ['Classification must be availability or compromise.'],
            ]);
        }

        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => ['A reason is required.']]);
        }

        $wasInitialized = tenancy()->initialized;
        if (! $wasInitialized) {
            tenancy()->initialize($tenant);
        }

        try {
            $recovery = TemporaryPasswordRecovery::query()->create([
                'tenant_id' => $tenant->id,
                'user_id' => $target->id,
                'reason' => substr($reason, 0, 512),
                'status' => TemporaryPasswordRecovery::STATUS_ACTIVE,
                'classification' => $classification,
                'created_by_type' => $createdByType,
                'created_by_id' => $createdById,
                'pea_case_id' => $peaCaseId,
                'activated_at' => now(),
                'expires_at' => now()->addHours(max(1, $ttlHours)),
            ]);
        } finally {
            if (! $wasInitialized) {
                tenancy()->end();
            }
        }

        $this->audit->log('emergency.temporary_password_recovery.activated', [
            'tenant_id' => (string) $tenant->id,
            'auditable_type' => TemporaryPasswordRecovery::class,
            'auditable_id' => (string) $recovery->id,
            'new_values' => [
                'target_user_id' => (string) $target->id,
                'classification' => $classification,
                'created_by_type' => $createdByType,
                'pea_case_id' => $peaCaseId,
            ],
        ]);

        return $recovery;
    }

    public function revoke(
        Tenant $tenant,
        TemporaryPasswordRecovery $recovery,
        ?string $revokedById,
        string $reason = 'revoked',
        bool $revokeSessions = true,
    ): TemporaryPasswordRecovery {
        $recovery->forceFill([
            'status' => TemporaryPasswordRecovery::STATUS_REVOKED,
            'revoked_at' => now(),
            'revoked_by_id' => $revokedById,
        ])->save();

        if ($revokeSessions) {
            $user = TenantUser::query()
                ->withoutGlobalScope('tenant')
                ->where('tenant_id', $tenant->id)
                ->whereKey($recovery->user_id)
                ->first();
            if ($user) {
                $this->sessions->revokeAllForUser($user);
            }
        }

        $this->audit->log('emergency.temporary_password_recovery.revoked', [
            'tenant_id' => (string) $tenant->id,
            'auditable_type' => TemporaryPasswordRecovery::class,
            'auditable_id' => (string) $recovery->id,
            'new_values' => [
                'reason' => $reason,
                'revoked_by_id' => $revokedById,
            ],
        ]);

        return $recovery->fresh();
    }

    public function hasActiveRecovery(Tenant $tenant, TenantUser $user): bool
    {
        $wasInitialized = tenancy()->initialized;
        if (! $wasInitialized) {
            tenancy()->initialize($tenant);
        }

        try {
            $row = TemporaryPasswordRecovery::query()
                ->where('tenant_id', $tenant->id)
                ->where('user_id', $user->id)
                ->where('status', TemporaryPasswordRecovery::STATUS_ACTIVE)
                ->orderByDesc('activated_at')
                ->first();

            if (! $row) {
                return false;
            }

            if ($row->expires_at->isPast()) {
                $row->forceFill([
                    'status' => TemporaryPasswordRecovery::STATUS_EXPIRED,
                    'revoked_at' => now(),
                ])->save();

                return false;
            }

            return true;
        } finally {
            if (! $wasInitialized) {
                tenancy()->end();
            }
        }
    }
}
