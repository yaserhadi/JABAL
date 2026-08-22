<?php

namespace App\Services\Platform;

use App\Models\PlatformEmergencyAuthorityCase;
use App\Models\PlatformUser;
use App\Support\Contracts\Audit\AuditLoggerInterface;
use Illuminate\Validation\ValidationException;
use Modules\Identity\Models\TenantUser;
use Modules\Identity\Services\SessionRegistryService;
use Modules\Identity\Services\TemporaryPasswordRecoveryService;
use Modules\Identity\Models\TemporaryPasswordRecovery;
use Modules\Tenancy\Models\Tenant;

/**
 * WAVE-5 Platform Emergency Authority — Platform-side recovery only.
 * Not ordinary Tenant Admin, not Generic Approval Engine, not business authority.
 */
class PlatformEmergencyAuthorityService
{
    public function __construct(
        protected TemporaryPasswordRecoveryService $passwordRecovery,
        protected SessionRegistryService $sessions,
        protected AuditLoggerInterface $audit,
    ) {}

    /**
     * @return array{case: PlatformEmergencyAuthorityCase, recovery: TemporaryPasswordRecovery|null}
     */
    public function invoke(
        PlatformUser $platformActor,
        Tenant $tenant,
        string $reason,
        string $classification,
        ?TenantUser $targetAdmin = null,
        bool $enableTemporaryPassword = true,
        int $ttlHours = 24,
    ): array {
        if (! $platformActor->exists) {
            abort(403, 'Platform actor required for PEA.');
        }

        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => ['Emergency reason is required.']]);
        }

        if (! in_array($classification, [
            PlatformEmergencyAuthorityCase::CLASS_AVAILABILITY,
            PlatformEmergencyAuthorityCase::CLASS_COMPROMISE,
        ], true)) {
            throw ValidationException::withMessages([
                'classification' => ['Must be availability or compromise.'],
            ]);
        }

        $case = PlatformEmergencyAuthorityCase::query()->create([
            'tenant_id' => $tenant->id,
            'platform_user_id' => $platformActor->id,
            'reason' => substr($reason, 0, 512),
            'classification' => $classification,
            'status' => PlatformEmergencyAuthorityCase::STATUS_ACTIVE,
            'purpose' => 'restore_tenant_admin_control',
            'emergency_tenant_user_id' => $targetAdmin?->id,
            'activated_at' => now(),
            'expires_at' => now()->addHours(max(1, $ttlHours)),
            'metadata' => [
                'enable_temporary_password' => $enableTemporaryPassword,
            ],
        ]);

        $this->audit->log('pea.invoked', [
            'tenant_id' => (string) $tenant->id,
            'auditable_type' => PlatformEmergencyAuthorityCase::class,
            'auditable_id' => (string) $case->id,
            'new_values' => [
                'platform_user_id' => (string) $platformActor->id,
                'classification' => $classification,
                'emergency_tenant_user_id' => $targetAdmin?->id,
            ],
        ]);

        $recovery = null;
        if ($enableTemporaryPassword && $targetAdmin) {
            if ($classification === PlatformEmergencyAuthorityCase::CLASS_COMPROMISE) {
                $this->sessions->revokeAllForUser($targetAdmin);
            }

            $recovery = $this->passwordRecovery->activate(
                $tenant,
                $targetAdmin,
                $reason,
                $classification === PlatformEmergencyAuthorityCase::CLASS_COMPROMISE
                    ? TemporaryPasswordRecovery::CLASS_COMPROMISE
                    : TemporaryPasswordRecovery::CLASS_AVAILABILITY,
                'platform',
                (string) $platformActor->id,
                (string) $case->id,
                $ttlHours,
            );

            $case->forceFill([
                'metadata' => array_merge($case->metadata ?? [], [
                    'temporary_recovery_id' => $recovery->id,
                ]),
            ])->save();
        }

        return ['case' => $case->fresh(), 'recovery' => $recovery];
    }

    public function close(
        PlatformUser $platformActor,
        PlatformEmergencyAuthorityCase $case,
        string $closeReason = 'return_to_normal',
        bool $revokeRecoveries = true,
    ): PlatformEmergencyAuthorityCase {
        $tenant = Tenant::query()->find($case->tenant_id);
        if ($tenant && $revokeRecoveries) {
            $wasInitialized = tenancy()->initialized;
            if (! $wasInitialized) {
                tenancy()->initialize($tenant);
            }
            try {
                $recoveries = TemporaryPasswordRecovery::query()
                    ->where('tenant_id', $tenant->id)
                    ->where('pea_case_id', $case->id)
                    ->where('status', TemporaryPasswordRecovery::STATUS_ACTIVE)
                    ->get();
                foreach ($recoveries as $recovery) {
                    $this->passwordRecovery->revoke(
                        $tenant,
                        $recovery,
                        (string) $platformActor->id,
                        'pea_case_closed',
                    );
                }
            } finally {
                if (! $wasInitialized) {
                    tenancy()->end();
                }
            }
        }

        $case->forceFill([
            'status' => PlatformEmergencyAuthorityCase::STATUS_CLOSED,
            'closed_at' => now(),
            'close_reason' => substr($closeReason, 0, 128),
        ])->save();

        $this->audit->log('pea.closed', [
            'tenant_id' => (string) $case->tenant_id,
            'auditable_type' => PlatformEmergencyAuthorityCase::class,
            'auditable_id' => (string) $case->id,
            'new_values' => [
                'platform_user_id' => (string) $platformActor->id,
                'close_reason' => $closeReason,
            ],
        ]);

        return $case->fresh();
    }
}
