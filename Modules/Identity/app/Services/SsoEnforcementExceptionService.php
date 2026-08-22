<?php

namespace Modules\Identity\Services;

use App\Support\Contracts\Audit\AuditLoggerInterface;
use Illuminate\Validation\ValidationException;
use Modules\Identity\Models\SsoEnforcementException;
use Modules\Identity\Models\TenantUser;
use Modules\Identity\Support\Auth\AuthenticationAdministrationAssurance;
use Modules\Identity\Support\Auth\AuthenticationAdministrationGate;
use Modules\Tenancy\Models\Tenant;

/**
 * WAVE-5: Per-User SSO Enforcement Exception administration.
 */
class SsoEnforcementExceptionService
{
    public function __construct(
        protected AuthenticationAdministrationGate $gate,
        protected SecurityPolicyService $policies,
        protected AuditLoggerInterface $audit,
    ) {}

    public function create(
        Tenant $tenant,
        TenantUser $actor,
        TenantUser $target,
        string $reason,
        ?\DateTimeInterface $expiresAt = null,
        bool $consumeFreshness = true,
    ): SsoEnforcementException {
        $this->gate->assertMayOperate(
            $tenant,
            $actor,
            AuthenticationAdministrationAssurance::OP_CHANGE_POLICY,
            $target,
            consumeFreshness: $consumeFreshness,
        );

        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => ['A reason is required.']]);
        }

        $closureMode = $this->policies->getSsoExceptionClosureMode($tenant);

        $wasInitialized = tenancy()->initialized;
        if (! $wasInitialized) {
            tenancy()->initialize($tenant);
        }

        try {
            $existing = SsoEnforcementException::query()
                ->where('tenant_id', $tenant->id)
                ->where('user_id', $target->id)
                ->where('status', SsoEnforcementException::STATUS_ACTIVE)
                ->first();

            if ($existing) {
                throw ValidationException::withMessages([
                    'exception' => ['An active enforcement exception already exists for this user.'],
                ]);
            }

            $exception = SsoEnforcementException::query()->create([
                'tenant_id' => $tenant->id,
                'user_id' => $target->id,
                'reason' => substr($reason, 0, 512),
                'status' => SsoEnforcementException::STATUS_ACTIVE,
                'closure_mode' => $closureMode,
                'created_by_user_id' => $actor->id,
                'expires_at' => $expiresAt,
            ]);
        } finally {
            if (! $wasInitialized) {
                tenancy()->end();
            }
        }

        $this->audit->log('sso_enforcement.exception.created', [
            'tenant_id' => (string) $tenant->id,
            'auditable_type' => SsoEnforcementException::class,
            'auditable_id' => (string) $exception->id,
            'new_values' => [
                'target_user_id' => (string) $target->id,
                'actor_user_id' => (string) $actor->id,
                'closure_mode' => $closureMode,
            ],
        ]);

        return $exception;
    }

    public function revoke(
        Tenant $tenant,
        TenantUser $actor,
        SsoEnforcementException $exception,
        string $reason = 'manual_revoke',
        bool $consumeFreshness = true,
    ): SsoEnforcementException {
        $this->gate->assertMayOperate(
            $tenant,
            $actor,
            AuthenticationAdministrationAssurance::OP_CHANGE_POLICY,
            null,
            consumeFreshness: $consumeFreshness,
        );

        $exception->forceFill([
            'status' => SsoEnforcementException::STATUS_REVOKED,
            'closed_at' => now(),
            'closed_by_user_id' => $actor->id,
            'close_reason' => substr($reason, 0, 64),
        ])->save();

        $this->audit->log('sso_enforcement.exception.revoked', [
            'tenant_id' => (string) $tenant->id,
            'auditable_type' => SsoEnforcementException::class,
            'auditable_id' => (string) $exception->id,
            'new_values' => [
                'actor_user_id' => (string) $actor->id,
                'close_reason' => $reason,
            ],
        ]);

        return $exception->fresh();
    }

    /**
     * Automatic closure: only after real SSO Ready (not Linked).
     */
    public function closeAutomaticOnReady(Tenant $tenant, TenantUser $user): void
    {
        $exceptions = SsoEnforcementException::query()
            ->where('tenant_id', $tenant->id)
            ->where('user_id', $user->id)
            ->where('status', SsoEnforcementException::STATUS_ACTIVE)
            ->where('closure_mode', SsoEnforcementException::CLOSURE_AUTOMATIC)
            ->get();

        foreach ($exceptions as $exception) {
            $exception->forceFill([
                'status' => SsoEnforcementException::STATUS_CLOSED,
                'closed_at' => now(),
                'close_reason' => 'automatic_ready',
            ])->save();

            $this->audit->log('sso_enforcement.exception.closed_automatic', [
                'tenant_id' => (string) $tenant->id,
                'auditable_type' => SsoEnforcementException::class,
                'auditable_id' => (string) $exception->id,
                'new_values' => [
                    'target_user_id' => (string) $user->id,
                    'close_reason' => 'automatic_ready',
                ],
            ]);
        }
    }
}
