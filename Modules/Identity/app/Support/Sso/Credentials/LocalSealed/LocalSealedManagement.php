<?php

namespace Modules\Identity\Support\Sso\Credentials\LocalSealed;

use Modules\Identity\Support\Sso\Credentials\SecretProviderManagement;
use Modules\Identity\Support\Sso\Credentials\SecretReference;

/** Privileged management surface — not for OIDC protocol consumers. */
final class LocalSealedManagement implements SecretProviderManagement
{
    public function __construct(private readonly LocalSealedEngine $engine) {}

    public function providerKey(): string
    {
        return LocalSealedEngine::PROVIDER_KEY;
    }

    public function provision(SecretReference $reference, string $plaintext): void
    {
        $this->engine->provision($reference, $plaintext);
    }

    public function rotate(SecretReference $reference, string $plaintext): void
    {
        $this->engine->rotate($reference, $plaintext);
    }

    public function revoke(SecretReference $reference): void
    {
        $this->engine->revoke($reference);
    }
}
