<?php

namespace Modules\Identity\Support\Sso\Credentials\LocalSealed;

use Modules\Identity\Support\Sso\Credentials\SecretProviderRuntime;
use Modules\Identity\Support\Sso\Credentials\SecretReference;
use Modules\Identity\Support\Sso\Credentials\SecretResolutionResult;

/** Runtime-only surface — no provision/rotate/revoke. */
final class LocalSealedRuntime implements SecretProviderRuntime
{
    public function __construct(private readonly LocalSealedEngine $engine) {}

    public function providerKey(): string
    {
        return LocalSealedEngine::PROVIDER_KEY;
    }

    public function exists(SecretReference $reference): bool
    {
        return $this->engine->exists($reference);
    }

    public function metadata(SecretReference $reference): array
    {
        return $this->engine->metadata($reference);
    }

    public function resolve(SecretReference $reference): SecretResolutionResult
    {
        return $this->engine->resolve($reference);
    }
}
