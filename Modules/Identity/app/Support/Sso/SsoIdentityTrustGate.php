<?php

namespace Modules\Identity\Support\Sso;

use Modules\Identity\Exceptions\SsoSecurityException;
use Modules\Identity\Models\TenantUser;

/**
 * WAVE-1 trust checks: IdP Email == User Email (fail closed) AND approved Connection domain.
 * Does not create, relink, merge, or mutate Users.
 */
final class SsoIdentityTrustGate
{
    public const REASON_IDP_EMAIL_MISSING = 'idp_email_missing';

    public const REASON_IDP_EMAIL_UNVERIFIED = 'idp_email_unverified';

    public const REASON_IDP_EMAIL_MISMATCH = 'idp_email_mismatch';

    public const REASON_SSO_DOMAIN_NOT_APPROVED = 'sso_domain_not_approved';

    public function __construct(
        protected SsoSecurityAudit $audit,
    ) {}

    /**
     * @param  list<string>  $approvedEmailDomains
     */
    public function evaluate(
        TenantUser $user,
        ?string $idpEmail,
        ?bool $idpEmailVerified,
        array $approvedEmailDomains,
        string $tenantId,
        string $purpose,
    ): ?string {
        $canonical = (string) $user->email;

        if (! is_string($idpEmail) || trim($idpEmail) === '') {
            $this->auditFailure($tenantId, $purpose, self::REASON_IDP_EMAIL_MISSING);

            return self::REASON_IDP_EMAIL_MISSING;
        }

        if ($idpEmailVerified === false) {
            $this->auditFailure($tenantId, $purpose, self::REASON_IDP_EMAIL_UNVERIFIED);

            return self::REASON_IDP_EMAIL_UNVERIFIED;
        }

        if (! SsoCanonicalEmail::equals($canonical, $idpEmail)) {
            $this->auditFailure($tenantId, $purpose, self::REASON_IDP_EMAIL_MISMATCH);

            return self::REASON_IDP_EMAIL_MISMATCH;
        }

        if (! SsoApprovedEmailDomainPolicy::allows($canonical, $approvedEmailDomains)) {
            $this->auditFailure($tenantId, $purpose, self::REASON_SSO_DOMAIN_NOT_APPROVED);

            return self::REASON_SSO_DOMAIN_NOT_APPROVED;
        }

        return null;
    }

    /**
     * @param  list<string>  $approvedEmailDomains
     */
    public function assert(
        TenantUser $user,
        ?string $idpEmail,
        ?bool $idpEmailVerified,
        array $approvedEmailDomains,
        string $tenantId,
        string $purpose,
    ): void {
        $reason = $this->evaluate(
            $user,
            $idpEmail,
            $idpEmailVerified,
            $approvedEmailDomains,
            $tenantId,
            $purpose,
        );

        if ($reason !== null) {
            throw new SsoSecurityException($reason);
        }
    }

    protected function auditFailure(string $tenantId, string $purpose, string $reason): void
    {
        $this->audit->record('sso.trust.rejected', [
            'tenant_id' => $tenantId,
            'purpose' => $purpose,
            'reason' => $reason,
            'status' => 'rejected',
        ]);
    }
}
