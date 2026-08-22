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
        protected SsoIdentityTrustGate $trustGate,
        protected SsoIdentityLifecycle $lifecycle,
    ) {}

    /**
     * Resolve solely by immutable issuer + EUID (subject) — never by email JIT / first-link.
     * After an existing link is found, IdP Email MUST equal canonical User Email (fail closed)
     * and the User Email domain MUST be on the Connection approved-domain list.
     *
     * @param  list<string>  $approvedEmailDomains
     */
    public function resolveExistingLinkOnly(
        Tenant $tenant,
        SsoValidatedClaims $claims,
        string $configuredIssuer,
        array $approvedEmailDomains = [],
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

        if (! $existing->isResolvableForLogin()) {
            return $this->hostIdentityNotProvisioned($tenant);
        }

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

        $trustFailure = $this->trustGate->evaluate(
            $user,
            $claims->email,
            $claims->emailVerified,
            $approvedEmailDomains,
            (string) $tenant->getTenantKey(),
            'ordinary_sso_login',
        );

        if ($trustFailure !== null) {
            // Existing binding + trust failure: needs attention; do not unlink or mutate User/Roles.
            $this->lifecycle->markNeedsAttention(
                $existing,
                (string) $tenant->getTenantKey(),
                $trustFailure,
            );

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
