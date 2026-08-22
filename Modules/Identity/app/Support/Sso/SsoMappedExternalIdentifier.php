<?php

namespace Modules\Identity\Support\Sso;

/**
 * Provider-stable external User identifier (EUID) plus issuer context.
 * Physical TenantUserIdentity columns remain issuer + subject (subject stores EUID).
 */
final class SsoMappedExternalIdentifier
{
    public function __construct(
        public readonly string $issuer,
        public readonly string $euid,
        public readonly string $providerFamily,
    ) {}
}
