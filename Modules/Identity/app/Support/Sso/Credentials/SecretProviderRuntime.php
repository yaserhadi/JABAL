<?php

namespace Modules\Identity\Support\Sso\Credentials;

/**
 * Least-privilege runtime surface for protocol consumers (token exchange, HS256 logout).
 */
interface SecretProviderRuntime
{
    public function providerKey(): string;

    public function exists(SecretReference $reference): bool;

    /**
     * @return array{status?:string,last_verified_at?:string|null}
     */
    public function metadata(SecretReference $reference): array;

    public function resolve(SecretReference $reference): SecretResolutionResult;
}
