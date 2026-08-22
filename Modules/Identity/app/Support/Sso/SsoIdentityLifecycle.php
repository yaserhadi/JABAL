<?php

namespace Modules\Identity\Support\Sso;

use Modules\Identity\Models\TenantUser;
use Modules\Identity\Models\TenantUserIdentity;
use Modules\Tenancy\Models\Tenant;

/**
 * WAVE-2 GAP-007: SSO Linked ≠ Login Verified ≠ SSO Ready.
 *
 * Ready is established only after a REAL ordinary SSO login + session path.
 * Association alone must never set Ready.
 */
final class SsoIdentityLifecycle
{
    public const STATUS_LINKED = 'linked';

    public const STATUS_LOGIN_VERIFIED = 'login_verified';

    public const STATUS_READY = 'ready';

    public const STATUS_VERIFICATION_FAILED = 'verification_failed';

    public const STATUS_NEEDS_ATTENTION = 'needs_attention';

    public function __construct(
        protected SsoSecurityAudit $audit,
    ) {}

    /**
     * Mark trusted association (SSO Linked only). Clears any prior Ready/Verified evidence.
     */
    public function markLinked(
        TenantUserIdentity $link,
        string $tenantId,
        ?string $idpConfigurationVersionId,
    ): TenantUserIdentity {
        $link->forceFill([
            'linked_at' => $link->linked_at ?? now(),
            'verification_status' => self::STATUS_LINKED,
            'linked_idp_configuration_version_id' => $idpConfigurationVersionId,
            'login_verified_at' => null,
            'ready_at' => null,
            'ready_idp_configuration_version_id' => null,
            'ready_canonical_email' => null,
            'last_verification_failure_at' => null,
            'last_verification_failure_reason' => null,
        ])->save();

        $this->audit->record('sso.lifecycle.linked', [
            'tenant_id' => $tenantId,
            'identity_link_id' => (string) $link->id,
            'idp_configuration_version_id' => $idpConfigurationVersionId,
            'status' => self::STATUS_LINKED,
            'reason' => 'association',
        ]);

        return $link->fresh();
    }

    /**
     * Ordinary SSO login + session establishment succeeded → Login Verified + Ready.
     */
    public function markLoginVerifiedAndReady(
        TenantUserIdentity $link,
        TenantUser $user,
        string $tenantId,
        string $idpConfigurationVersionId,
        string $reason = 'ordinary_sso_login',
    ): TenantUserIdentity {
        if (! $link->exists || $link->user_id === null) {
            return $link;
        }

        $canonical = SsoCanonicalEmail::normalize((string) $user->email);

        $already = $link->verification_status === self::STATUS_READY
            && $link->ready_at !== null
            && (string) $link->ready_idp_configuration_version_id === $idpConfigurationVersionId
            && is_string($link->ready_canonical_email)
            && SsoCanonicalEmail::equals($link->ready_canonical_email, $canonical);

        if ($already) {
            $this->audit->record('sso.lifecycle.ready_idempotent', [
                'tenant_id' => $tenantId,
                'identity_link_id' => (string) $link->id,
                'idp_configuration_version_id' => $idpConfigurationVersionId,
                'status' => self::STATUS_READY,
                'reason' => $reason,
            ]);

            $this->tryPromoteResetCandidate($link, $tenantId);
            $this->tryCloseAutomaticException($link, $user, $tenantId);

            return $link;
        }

        $now = now();
        $link->forceFill([
            'verification_status' => self::STATUS_READY,
            'login_verified_at' => $link->login_verified_at ?? $now,
            'ready_at' => $now,
            'ready_idp_configuration_version_id' => $idpConfigurationVersionId,
            'ready_canonical_email' => $canonical,
            'last_verification_failure_at' => null,
            'last_verification_failure_reason' => null,
        ])->save();

        $this->audit->record('sso.lifecycle.login_verified', [
            'tenant_id' => $tenantId,
            'identity_link_id' => (string) $link->id,
            'idp_configuration_version_id' => $idpConfigurationVersionId,
            'status' => self::STATUS_LOGIN_VERIFIED,
            'reason' => $reason,
        ]);

        $this->audit->record('sso.lifecycle.ready', [
            'tenant_id' => $tenantId,
            'identity_link_id' => (string) $link->id,
            'idp_configuration_version_id' => $idpConfigurationVersionId,
            'status' => self::STATUS_READY,
            'reason' => $reason,
        ]);

        $fresh = $link->fresh();
        $this->tryPromoteResetCandidate($fresh, $tenantId);
        $this->tryCloseAutomaticException($fresh, $user, $tenantId);

        return $fresh;
    }

    /**
     * WAVE-5: Automatic Exception closure only after real Ready.
     */
    protected function tryCloseAutomaticException(?TenantUserIdentity $link, TenantUser $user, string $tenantId): void
    {
        if (! $link instanceof TenantUserIdentity) {
            return;
        }

        $tenant = Tenant::query()->find($tenantId);
        if (! $tenant instanceof Tenant) {
            return;
        }

        try {
            app(\Modules\Identity\Services\SsoEnforcementExceptionService::class)
                ->closeAutomaticOnReady($tenant, $user);
        } catch (\Throwable) {
            // Closure failure must not undo Ready.
        }
    }

    /**
     * WAVE-4: if this Ready link is a Reset SSO candidate, promote safely.
     */
    protected function tryPromoteResetCandidate(?TenantUserIdentity $link, string $tenantId): void
    {
        if (! $link instanceof TenantUserIdentity) {
            return;
        }

        $tenant = Tenant::query()->find($tenantId);
        if (! $tenant instanceof Tenant) {
            return;
        }

        try {
            app(\Modules\Identity\Services\ResetSsoService::class)
                ->tryPromoteAfterReady($tenant, $link);
        } catch (\Throwable) {
            // Promotion failure must not undo Ready evidence; Admin can retry.
        }
    }

    /**
     * Class B: link remains; Ready is No; User/Roles untouched.
     */
    public function markVerificationFailed(
        TenantUserIdentity $link,
        string $tenantId,
        string $reason,
    ): TenantUserIdentity {
        $link->forceFill([
            'verification_status' => self::STATUS_VERIFICATION_FAILED,
            'ready_at' => null,
            'ready_idp_configuration_version_id' => null,
            'ready_canonical_email' => null,
            'last_verification_failure_at' => now(),
            'last_verification_failure_reason' => substr($reason, 0, 64),
        ])->save();

        $this->audit->record('sso.lifecycle.verification_failed', [
            'tenant_id' => $tenantId,
            'identity_link_id' => (string) $link->id,
            'status' => self::STATUS_VERIFICATION_FAILED,
            'reason' => substr($reason, 0, 64),
        ]);

        return $link->fresh();
    }

    /**
     * Class A trust-bundle invalid for an existing link (email/domain/etc.) — needs attention, not unlink.
     */
    public function markNeedsAttention(
        TenantUserIdentity $link,
        string $tenantId,
        string $reason,
    ): TenantUserIdentity {
        $link->forceFill([
            'verification_status' => self::STATUS_NEEDS_ATTENTION,
            'ready_at' => null,
            'ready_idp_configuration_version_id' => null,
            'ready_canonical_email' => null,
            'login_verified_at' => null,
            'last_verification_failure_at' => now(),
            'last_verification_failure_reason' => substr($reason, 0, 64),
        ])->save();

        $this->audit->record('sso.lifecycle.needs_attention', [
            'tenant_id' => $tenantId,
            'identity_link_id' => (string) $link->id,
            'status' => self::STATUS_NEEDS_ATTENTION,
            'reason' => substr($reason, 0, 64),
        ]);

        return $link->fresh();
    }

    /**
     * Material Connection/config change: Ready must be re-proven via ordinary SSO login.
     */
    public function invalidateReadyForTenant(Tenant $tenant, string $reason): int
    {
        $updated = 0;
        TenantUserIdentity::query()
            ->where('tenant_id', $tenant->id)
            ->where(function ($q) {
                $q->where('verification_status', self::STATUS_READY)
                    ->orWhereNotNull('ready_at')
                    ->orWhereNotNull('login_verified_at');
            })
            ->orderBy('id')
            ->chunkById(100, function ($links) use ($tenant, $reason, &$updated) {
                foreach ($links as $link) {
                    /** @var TenantUserIdentity $link */
                    $link->forceFill([
                        'verification_status' => self::STATUS_LINKED,
                        'login_verified_at' => null,
                        'ready_at' => null,
                        'ready_idp_configuration_version_id' => null,
                        'ready_canonical_email' => null,
                    ])->save();
                    $updated++;
                }
            });

        if ($updated > 0) {
            $this->audit->record('sso.lifecycle.ready_invalidated', [
                'tenant_id' => (string) $tenant->id,
                'status' => self::STATUS_LINKED,
                'reason' => substr($reason, 0, 64),
                'identity_links_affected' => $updated,
            ]);
        }

        return $updated;
    }

    public function isEffectivelyReady(
        TenantUserIdentity $link,
        TenantUser $user,
        ?string $activeIdpConfigurationVersionId,
    ): bool {
        if ($link->verification_status !== self::STATUS_READY || $link->ready_at === null) {
            return false;
        }

        if (! is_string($activeIdpConfigurationVersionId) || $activeIdpConfigurationVersionId === '') {
            return false;
        }

        if ((string) $link->ready_idp_configuration_version_id !== $activeIdpConfigurationVersionId) {
            return false;
        }

        if (! is_string($link->ready_canonical_email) || $link->ready_canonical_email === '') {
            return false;
        }

        return SsoCanonicalEmail::equals($link->ready_canonical_email, (string) $user->email);
    }

    /**
     * Linked-not-Ready (or stale Ready) requires ordinary SSO session proof — not enrollment context.
     */
    public function requiresOrdinarySessionProof(
        TenantUserIdentity $link,
        TenantUser $user,
        ?string $activeIdpConfigurationVersionId,
    ): bool {
        return ! $this->isEffectivelyReady($link, $user, $activeIdpConfigurationVersionId);
    }

    /**
     * @return array{verification_status: string, linked: bool, login_verified: bool, ready: bool, needs_attention: bool}
     */
    public function publicStatus(
        TenantUserIdentity $link,
        TenantUser $user,
        ?string $activeIdpConfigurationVersionId,
    ): array {
        $ready = $this->isEffectivelyReady($link, $user, $activeIdpConfigurationVersionId);
        $status = (string) ($link->verification_status ?? self::STATUS_LINKED);
        if ($ready) {
            $status = self::STATUS_READY;
        } elseif (in_array($status, [self::STATUS_READY, self::STATUS_LOGIN_VERIFIED], true)) {
            // Stale Ready evidence relative to current email/version.
            $status = self::STATUS_LINKED;
        }

        return [
            'verification_status' => $status,
            'linked' => $link->linked_at !== null || $link->exists,
            'login_verified' => $ready || $link->login_verified_at !== null,
            'ready' => $ready,
            'needs_attention' => $status === self::STATUS_NEEDS_ATTENTION
                || $status === self::STATUS_VERIFICATION_FAILED,
        ];
    }
}
