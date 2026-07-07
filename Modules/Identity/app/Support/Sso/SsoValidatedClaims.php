<?php

namespace Modules\Identity\Support\Sso;

/**
 * Validated OIDC identity claims for JABAL link resolution (ID token primary).
 */
final class SsoValidatedClaims
{
    public function __construct(
        public readonly string $issuer,
        public readonly string $subject,
        public readonly ?string $email,
        public readonly ?bool $emailVerified,
    ) {}

    public function emailVerifiedForFirstLink(): bool
    {
        return $this->emailVerified === true;
    }
}
