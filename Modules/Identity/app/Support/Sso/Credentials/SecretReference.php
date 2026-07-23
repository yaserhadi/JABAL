<?php

namespace Modules\Identity\Support\Sso\Credentials;

/**
 * Opaque logical credential reference (never a caller-controlled filesystem path).
 */
final class SecretReference
{
    public readonly string $provider;

    public function __construct(
        string $provider,
        public readonly string $reference,
        public readonly string $credentialType,
        public readonly ?string $versionPolicy = null,
        public readonly ?string $environmentScope = null,
        public readonly ?string $status = null,
    ) {
        $this->provider = SecretProviderKey::canonicalize($provider);

        if ($this->reference === '' || $this->credentialType === '') {
            throw new \InvalidArgumentException('SecretReference requires provider, reference, and credentialType.');
        }

        if ($this->looksLikeFilesystemPath($this->reference)) {
            throw new \InvalidArgumentException('SecretReference must be an opaque logical key, not a filesystem path.');
        }
    }

    public static function fromVersionAttributes(
        string $provider,
        string $reference,
        string $credentialType,
        ?string $versionPolicy = null,
        ?string $environmentScope = null,
        ?string $status = null,
    ): self {
        return new self($provider, $reference, $credentialType, $versionPolicy, $environmentScope, $status);
    }

    private function looksLikeFilesystemPath(string $reference): bool
    {
        if (str_contains($reference, '..')) {
            return true;
        }

        if (preg_match('#^[A-Za-z]:[\\\\/]#', $reference) === 1) {
            return true;
        }

        if (str_starts_with($reference, '/') || str_starts_with($reference, '\\')) {
            return true;
        }

        return false;
    }
}
