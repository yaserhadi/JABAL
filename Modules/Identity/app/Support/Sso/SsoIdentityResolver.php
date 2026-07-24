<?php

namespace Modules\Identity\Support\Sso;

use App\Support\Contracts\Audit\AuditLoggerInterface;
use Modules\Identity\Models\Membership;
use Modules\Identity\Models\TenantUser;
use Modules\Identity\Models\TenantUserIdentity;
use Modules\Tenancy\Models\Tenant;

/**
 * Existing issuer+subject Identity Link only — never silent email JIT / first-link.
 */
final class SsoIdentityResolver
{
    public function __construct(
        protected AuditLoggerInterface $auditLogger,
    ) {}

    /**
     * D10 / BK-097: resolve solely by immutable issuer + subject — never by email.
     * Failures collapse to identity_not_provisioned so responses/audits do not enumerate.
     */
    public function resolveExistingLinkOnly(
        Tenant $tenant,
        SsoValidatedClaims $claims,
        string $configuredIssuer,
    ): SsoIdentityResolutionResult {
        $normalizedConfigured = rtrim(trim($configuredIssuer), '/');
        $normalizedClaimsIssuer = rtrim(trim($claims->issuer), '/');

        if ($normalizedConfigured !== $normalizedClaimsIssuer) {
            $this->auditHostResolutionFailure($tenant, SsoIdentityResolutionResult::REASON_ISSUER_MISMATCH);

            return SsoIdentityResolutionResult::failed(SsoIdentityResolutionResult::REASON_ISSUER_MISMATCH);
        }

        $matches = TenantUserIdentity::query()
            ->where('tenant_id', $tenant->id)
            ->where('issuer', $normalizedClaimsIssuer)
            ->where('subject', $claims->subject)
            ->limit(2)
            ->get();

        if ($matches->count() !== 1) {
            return $this->hostIdentityNotProvisioned($tenant);
        }

        /** @var TenantUserIdentity $existing */
        $existing = $matches->first();

        $user = TenantUser::query()
            ->withoutGlobalScope('tenant')
            ->where('tenant_id', $tenant->id)
            ->where('id', $existing->user_id)
            ->first();

        if (! $user || $user->trashed()) {
            return $this->hostIdentityNotProvisioned($tenant);
        }

        if (! $this->hasActiveMembership($tenant, $user)) {
            return $this->hostIdentityNotProvisioned($tenant);
        }

        return SsoIdentityResolutionResult::success($user, $existing);
    }

    protected function hostIdentityNotProvisioned(Tenant $tenant): SsoIdentityResolutionResult
    {
        $this->auditHostResolutionFailure($tenant, SsoIdentityResolutionResult::REASON_IDENTITY_NOT_PROVISIONED);

        return SsoIdentityResolutionResult::failed(SsoIdentityResolutionResult::REASON_IDENTITY_NOT_PROVISIONED);
    }

    protected function auditHostResolutionFailure(Tenant $tenant, string $reason): void
    {
        $this->auditLogger->log('sso.identity.host_resolution_failed', [
            'tenant_id' => $tenant->getTenantKey(),
            'reason' => $reason,
            // No email, subject, user_id, membership, or link identifiers (non-enumerating).
        ]);
    }

    protected function hasActiveMembership(Tenant $tenant, TenantUser $user): bool
    {
        return Membership::query()
            ->where('tenant_id', $tenant->id)
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->exists();
    }
}
