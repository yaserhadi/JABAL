<?php

namespace Modules\Identity\Support\Sso;

use Modules\Identity\Models\TenantUser;
use Modules\Identity\Models\TenantUserIdentity;

final class SsoIdentityResolutionResult
{
    public const REASON_ISSUER_MISMATCH = 'issuer_mismatch';
    public const REASON_IDENTITY_NOT_PROVISIONED = 'identity_not_provisioned';

    private function __construct(
        public readonly ?TenantUser $user,
        public readonly ?TenantUserIdentity $identityLink,
        public readonly ?string $failureReason,
    ) {}

    public static function success(TenantUser $user, TenantUserIdentity $link): self
    {
        return new self($user, $link, null);
    }

    public static function failed(string $reason): self
    {
        return new self(null, null, $reason);
    }

    public function succeeded(): bool
    {
        return $this->user !== null && $this->failureReason === null;
    }
}
