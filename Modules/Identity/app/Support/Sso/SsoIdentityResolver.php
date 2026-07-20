<?php

namespace Modules\Identity\Support\Sso;

use App\Support\Contracts\Audit\AuditLoggerInterface;
use Illuminate\Support\Facades\Auth;
use Modules\Identity\Models\Membership;
use Modules\Identity\Models\TenantUser;
use Modules\Identity\Models\TenantUserIdentity;
use Modules\Tenancy\Models\Tenant;

/**
 * Link-only + first-link resolver. Never creates TenantUser or Membership.
 */
final class SsoIdentityResolver
{
    public function __construct(
        protected AuditLoggerInterface $auditLogger,
    ) {}

    public function resolve(
        Tenant $tenant,
        SsoValidatedClaims $claims,
        string $configuredIssuer,
    ): SsoIdentityResolutionResult {
        $normalizedConfigured = rtrim(trim($configuredIssuer), '/');
        $normalizedClaimsIssuer = rtrim(trim($claims->issuer), '/');

        if ($normalizedConfigured !== $normalizedClaimsIssuer) {
            return SsoIdentityResolutionResult::failed(SsoIdentityResolutionResult::REASON_ISSUER_MISMATCH);
        }

        $existing = TenantUserIdentity::query()
            ->where('tenant_id', $tenant->id)
            ->where('issuer', $normalizedClaimsIssuer)
            ->where('subject', $claims->subject)
            ->first();

        if ($existing) {
            $user = TenantUser::query()
                ->withoutGlobalScope('tenant')
                ->where('tenant_id', $tenant->id)
                ->where('id', $existing->user_id)
                ->first();

            if (! $user || $user->trashed()) {
                return SsoIdentityResolutionResult::failed(SsoIdentityResolutionResult::REASON_USER_INACTIVE);
            }

            if (! $this->hasActiveMembership($tenant, $user)) {
                return SsoIdentityResolutionResult::failed(SsoIdentityResolutionResult::REASON_MEMBERSHIP_INACTIVE);
            }

            return SsoIdentityResolutionResult::success($user, $existing, false);
        }

        return $this->attemptFirstLink($tenant, $claims, $normalizedClaimsIssuer);
    }

    /**
     * BK-082 Host / D10: existing issuer+subject Identity Link only — never attemptFirstLink / silent JIT.
     *
     * Email and profile claims are attributes only (never authority). Failures collapse to
     * identity_not_provisioned so Host responses/audits do not enumerate user/link/membership.
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

        // D10: resolve solely by immutable issuer + subject — never by email.
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

        return SsoIdentityResolutionResult::success($user, $existing, false);
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

    protected function attemptFirstLink(
        Tenant $tenant,
        SsoValidatedClaims $claims,
        string $issuer,
    ): SsoIdentityResolutionResult {
        if ($claims->email === null || $claims->email === '') {
            return SsoIdentityResolutionResult::failed(SsoIdentityResolutionResult::REASON_NO_MATCH);
        }

        if (! $claims->emailVerifiedForFirstLink()) {
            return SsoIdentityResolutionResult::failed(SsoIdentityResolutionResult::REASON_EMAIL_NOT_VERIFIED);
        }

        $candidates = TenantUser::query()
            ->withoutGlobalScope('tenant')
            ->where('tenant_id', $tenant->id)
            ->where('email', $claims->email)
            ->get();

        $eligible = $candidates->filter(function (TenantUser $user) use ($tenant) {
            return ! $user->trashed() && $this->hasActiveMembership($tenant, $user);
        })->values();

        if ($eligible->count() === 0) {
            return SsoIdentityResolutionResult::failed(SsoIdentityResolutionResult::REASON_NO_MATCH);
        }

        if ($eligible->count() > 1) {
            return SsoIdentityResolutionResult::failed(SsoIdentityResolutionResult::REASON_AMBIGUOUS_EMAIL);
        }

        /** @var TenantUser $user */
        $user = $eligible->first();

        $link = TenantUserIdentity::query()->create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'issuer' => $issuer,
            'subject' => $claims->subject,
            'email_at_link' => $claims->email,
        ]);

        $this->auditLogger->log('sso.identity.first_link_created', [
            'tenant_id' => $tenant->getTenantKey(),
            'auditable_type' => TenantUserIdentity::class,
            'auditable_id' => (string) $link->getKey(),
            'old_values' => null,
            'new_values' => [
                'issuer' => $issuer,
                'subject' => $claims->subject,
                'user_id' => $user->id,
            ],
            'changed_by' => Auth::id(),
        ]);

        return SsoIdentityResolutionResult::success($user, $link, true);
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
