<?php

namespace Modules\Identity\Support\Sso\Credentials;

/**
 * Privileged provisioning surface — must not be injected into OIDC protocol services.
 */
interface SecretProviderManagement
{
    public function providerKey(): string;

    /**
     * @param  non-empty-string  $plaintext  Never logged; never returned after success.
     */
    public function provision(SecretReference $reference, string $plaintext): void;

    public function rotate(SecretReference $reference, string $plaintext): void;

    public function revoke(SecretReference $reference): void;
}
