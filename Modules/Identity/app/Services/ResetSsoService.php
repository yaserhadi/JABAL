<?php

namespace Modules\Identity\Services;

use App\Support\Contracts\Audit\AuditLoggerInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Identity\Models\SsoIdentityBindingHistory;
use Modules\Identity\Models\SsoIdentityResetTransaction;
use Modules\Identity\Models\TenantUser;
use Modules\Identity\Models\TenantUserIdentity;
use Modules\Identity\Support\Auth\AuthenticationAdministrationAssurance;
use Modules\Identity\Support\Auth\AuthenticationAdministrationGate;
use Modules\Identity\Support\Sso\SsoIdentityLifecycle;
use Modules\Tenancy\Models\Tenant;

/**
 * WAVE-4 GAP-010 Reset SSO: candidate binding lifecycle without destroying current trusted binding
 * unless explicitly marked compromised.
 */
class ResetSsoService
{
    public function __construct(
        protected AuthenticationAdministrationGate $gate,
        protected SsoIdentityLifecycle $lifecycle,
        protected AuditLoggerInterface $audit,
    ) {}

    /**
     * @return array{transaction: SsoIdentityResetTransaction}
     */
    public function initiate(
        Tenant $tenant,
        TenantUser $actor,
        TenantUser $target,
        bool $compromisedCurrent = false,
        string $purpose = SsoIdentityResetTransaction::PURPOSE_RESET_SSO,
        bool $consumeFreshness = true,
        bool $skipGate = false,
    ): array {
        if (! $skipGate) {
            $this->gate->assertMayOperate(
                $tenant,
                $actor,
                AuthenticationAdministrationAssurance::OP_RESET_SSO,
                $target,
                consumeFreshness: $consumeFreshness,
            );
        }

        $wasInitialized = tenancy()->initialized;
        if (! $wasInitialized) {
            tenancy()->initialize($tenant);
        }

        try {
            return DB::connection('tenant')->transaction(function () use (
                $tenant,
                $actor,
                $target,
                $compromisedCurrent,
                $purpose
            ) {
                $pending = SsoIdentityResetTransaction::query()
                    ->where('tenant_id', $tenant->id)
                    ->where('user_id', $target->id)
                    ->where('status', SsoIdentityResetTransaction::STATUS_PENDING)
                    ->first();

                if ($pending) {
                    throw ValidationException::withMessages([
                        'reset_sso' => ['A Reset SSO transaction is already pending for this user.'],
                    ]);
                }

                $current = TenantUserIdentity::query()
                    ->where('tenant_id', $tenant->id)
                    ->where('user_id', $target->id)
                    ->where('binding_role', TenantUserIdentity::ROLE_CURRENT)
                    ->first();

                if ($current) {
                    $this->snapshotHistory($current, null, 'snapshot');

                    if ($compromisedCurrent) {
                        $current->forceFill([
                            'binding_role' => TenantUserIdentity::ROLE_SECURITY_HELD,
                            'security_held_at' => now(),
                            'security_held_reason' => 'admin_declared_compromised',
                            'verification_status' => SsoIdentityLifecycle::STATUS_NEEDS_ATTENTION,
                            'ready_at' => null,
                            'ready_idp_configuration_version_id' => null,
                            'ready_canonical_email' => null,
                            'login_verified_at' => null,
                        ])->save();
                        $this->snapshotHistory($current, null, 'security_held');
                    } else {
                        // Invalidate Ready evidence; keep current trusted binding until candidate promotes.
                        $this->lifecycle->markLinked(
                            $current,
                            (string) $tenant->id,
                            $current->linked_idp_configuration_version_id
                        );
                    }
                }

                $txn = SsoIdentityResetTransaction::query()->create([
                    'tenant_id' => $tenant->id,
                    'user_id' => $target->id,
                    'initiated_by_user_id' => $actor->id,
                    'purpose' => $purpose,
                    'status' => SsoIdentityResetTransaction::STATUS_PENDING,
                    'current_identity_id' => $current?->id,
                    'compromised_current' => $compromisedCurrent,
                    'same_euid_reverification' => false,
                ]);

                $this->audit->log('auth_admin.reset_sso.initiated', [
                    'tenant_id' => (string) $tenant->id,
                    'auditable_type' => SsoIdentityResetTransaction::class,
                    'auditable_id' => (string) $txn->id,
                    'new_values' => [
                        'target_user_id' => (string) $target->id,
                        'actor_user_id' => (string) $actor->id,
                        'compromised_current' => $compromisedCurrent,
                        'current_identity_id' => $current?->id,
                    ],
                ]);

                return ['transaction' => $txn];
            });
        } finally {
            if (! $wasInitialized) {
                tenancy()->end();
            }
        }
    }

    /**
     * Attach a newly Linked candidate identity to a pending Reset SSO transaction.
     * Association = Linked only; does not promote.
     */
    public function attachCandidate(
        Tenant $tenant,
        SsoIdentityResetTransaction $txn,
        TenantUserIdentity $candidate,
    ): SsoIdentityResetTransaction {
        if (! $txn->isPending() || (string) $txn->tenant_id !== (string) $tenant->id) {
            throw ValidationException::withMessages([
                'reset_sso' => ['Reset SSO transaction is not pending.'],
            ]);
        }

        if ((string) $candidate->user_id !== (string) $txn->user_id) {
            throw ValidationException::withMessages([
                'reset_sso' => ['Candidate identity does not match transaction user.'],
            ]);
        }

        $candidate->forceFill([
            'binding_role' => TenantUserIdentity::ROLE_CANDIDATE,
        ])->save();

        $this->lifecycle->markLinked(
            $candidate,
            (string) $tenant->id,
            $candidate->linked_idp_configuration_version_id
        );

        $txn->update(['candidate_identity_id' => $candidate->id]);

        $this->audit->log('auth_admin.reset_sso.candidate_linked', [
            'tenant_id' => (string) $tenant->id,
            'auditable_type' => SsoIdentityResetTransaction::class,
            'auditable_id' => (string) $txn->id,
            'new_values' => [
                'candidate_identity_id' => (string) $candidate->id,
            ],
        ]);

        return $txn->fresh();
    }

    /**
     * Mark pending transaction as same-EUID re-verification of the current binding
     * (email change / remapping without a second issuer+subject row).
     */
    public function markSameEuidReverification(
        Tenant $tenant,
        SsoIdentityResetTransaction $txn,
    ): SsoIdentityResetTransaction {
        if (! $txn->isPending()) {
            throw ValidationException::withMessages([
                'reset_sso' => ['Reset SSO transaction is not pending.'],
            ]);
        }

        if ($txn->current_identity_id === null) {
            throw ValidationException::withMessages([
                'reset_sso' => ['No current binding available for same-EUID re-verification.'],
            ]);
        }

        $txn->update([
            'same_euid_reverification' => true,
            'candidate_identity_id' => $txn->current_identity_id,
        ]);

        $this->audit->log('auth_admin.reset_sso.same_euid_reverification', [
            'tenant_id' => (string) $tenant->id,
            'auditable_type' => SsoIdentityResetTransaction::class,
            'auditable_id' => (string) $txn->id,
        ]);

        return $txn->fresh();
    }

    /**
     * After WAVE-2 Ready on a candidate (or same-EUID current), promote safely.
     */
    public function tryPromoteAfterReady(Tenant $tenant, TenantUserIdentity $link): ?SsoIdentityResetTransaction
    {
        if ($link->verification_status !== SsoIdentityLifecycle::STATUS_READY) {
            return null;
        }

        $txn = SsoIdentityResetTransaction::query()
            ->where('tenant_id', $tenant->id)
            ->where('user_id', $link->user_id)
            ->where('status', SsoIdentityResetTransaction::STATUS_PENDING)
            ->where(function ($q) use ($link) {
                $q->where('candidate_identity_id', $link->id)
                    ->orWhere(function ($q2) use ($link) {
                        $q2->where('same_euid_reverification', true)
                            ->where('current_identity_id', $link->id);
                    });
            })
            ->first();

        if (! $txn) {
            return null;
        }

        return DB::connection('tenant')->transaction(function () use ($tenant, $link, $txn) {
            $txn->refresh();
            if (! $txn->isPending()) {
                return $txn;
            }

            if ($txn->same_euid_reverification) {
                // Same row re-verified — remain current; close transaction.
                $link->forceFill([
                    'binding_role' => TenantUserIdentity::ROLE_CURRENT,
                    'security_held_at' => null,
                    'security_held_reason' => null,
                ])->save();

                $this->snapshotHistory($link, $txn->id, 'promoted');
            } else {
                $old = $txn->current_identity_id
                    ? TenantUserIdentity::query()->whereKey($txn->current_identity_id)->first()
                    : null;

                if ($old && (string) $old->id !== (string) $link->id) {
                    $this->snapshotHistory($old, $txn->id, 'superseded');
                    $old->forceFill([
                        'binding_role' => TenantUserIdentity::ROLE_HISTORICAL,
                        'superseded_at' => now(),
                        'superseded_by_identity_id' => $link->id,
                        'ready_at' => null,
                        'ready_idp_configuration_version_id' => null,
                        'ready_canonical_email' => null,
                        'verification_status' => SsoIdentityLifecycle::STATUS_LINKED,
                    ])->save();
                }

                $link->forceFill([
                    'binding_role' => TenantUserIdentity::ROLE_CURRENT,
                    'security_held_at' => null,
                    'security_held_reason' => null,
                ])->save();

                $this->snapshotHistory($link, $txn->id, 'promoted');
            }

            $txn->update([
                'status' => SsoIdentityResetTransaction::STATUS_COMPLETED,
                'completed_at' => now(),
            ]);

            $this->audit->log('auth_admin.reset_sso.promoted', [
                'tenant_id' => (string) $tenant->id,
                'auditable_type' => SsoIdentityResetTransaction::class,
                'auditable_id' => (string) $txn->id,
                'new_values' => [
                    'current_identity_id' => (string) $link->id,
                    'same_euid_reverification' => (bool) $txn->same_euid_reverification,
                ],
            ]);

            return $txn->fresh();
        });
    }

    /**
     * Candidate association failure must not destroy non-compromised current binding.
     */
    public function recordCandidateFailure(
        Tenant $tenant,
        SsoIdentityResetTransaction $txn,
        string $reason,
    ): void {
        $this->audit->log('auth_admin.reset_sso.candidate_failed', [
            'tenant_id' => (string) $tenant->id,
            'auditable_type' => SsoIdentityResetTransaction::class,
            'auditable_id' => (string) $txn->id,
            'new_values' => [
                'reason' => $reason,
                'current_preserved' => ! $txn->compromised_current,
            ],
        ]);

        // Leave transaction pending so Admin can retry; current stays unless compromised.
        if ($txn->candidate_identity_id && ! $txn->same_euid_reverification) {
            $candidate = TenantUserIdentity::query()->whereKey($txn->candidate_identity_id)->first();
            if ($candidate && $candidate->binding_role === TenantUserIdentity::ROLE_CANDIDATE) {
                $candidate->delete();
                $txn->update(['candidate_identity_id' => null]);
            }
        }
    }

    protected function snapshotHistory(
        TenantUserIdentity $identity,
        ?string $txnId,
        string $event,
    ): void {
        SsoIdentityBindingHistory::query()->create([
            'tenant_id' => $identity->tenant_id,
            'user_id' => $identity->user_id,
            'identity_id' => $identity->id,
            'reset_transaction_id' => $txnId,
            'issuer' => $identity->issuer,
            'subject' => $identity->subject,
            'email_at_link' => $identity->email_at_link,
            'verification_status' => $identity->verification_status,
            'binding_role' => $identity->binding_role,
            'event' => $event,
            'ready_at' => $identity->ready_at,
        ]);
    }
}
