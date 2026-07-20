<?php

namespace Modules\Identity\Support\Sso;

use Modules\Identity\Models\TenantUser;
use Modules\Identity\Models\TenantUserIdentity;

final class SsoIdentityResolutionResult
{
    public const REASON_ISSUER_MISMATCH = 'issuer_mismatch';
    public const REASON_NO_MATCH = 'no_matching_user';
    public const REASON_AMBIGUOUS_EMAIL = 'ambiguous_email';
    public const REASON_EMAIL_NOT_VERIFIED = 'email_not_verified';
    public const REASON_MEMBERSHIP_INACTIVE = 'membership_inactive';
    public const REASON_USER_INACTIVE = 'user_inactive';
    public const REASON_IDENTITY_NOT_PROVISIONED = 'identity_not_provisioned';

    private function __construct(
        public readonly ?TenantUser $user,
        public readonly ?TenantUserIdentity $identityLink,
        public readonly bool $createdLink,
        public readonly ?string $failureReason,
    ) {}

    public static function success(TenantUser $user, TenantUserIdentity $link, bool $createdLink): self
    {
        return new self($user, $link, $createdLink, null);
    }

    public static function failed(string $reason): self
    {
        return new self(null, null, false, $reason);
    }

    public function succeeded(): bool
    {
        return $this->user !== null && $this->failureReason === null;
    }
}
