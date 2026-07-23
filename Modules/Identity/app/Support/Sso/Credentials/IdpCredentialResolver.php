<?php

namespace Modules\Identity\Support\Sso\Credentials;

use Modules\Identity\Models\TenantSsoConfigVersion;
use Modules\Tenancy\Models\Tenant;

/**
 * Fail-closed IdP credential resolver for version-owned secret references.
 *
 * Foundation phase: validates authority and metadata; does not decrypt
 * client_secret_encrypted and does not register local_sealed yet.
 */
final class IdpCredentialResolver
{
    public const SOURCE_LEGACY_ENCRYPTED = 'legacy_encrypted';

    public const SOURCE_REFERENCE = 'reference';

    public function __construct(
        private readonly SecretProviderRegistry $registry,
    ) {}

    /**
     * Resolve a version-owned credential reference. Never reads parent ciphertext.
     * Never falls back to client_secret_encrypted.
     *
     * @throws CredentialResolutionException
     */
    public function resolveForVersion(Tenant $tenant, TenantSsoConfigVersion $version): SecretResolutionResult
    {
        $this->assertVersionAuthority($tenant, $version);
        $this->assertReferenceSource($version);
        $reference = $this->buildReferenceFromVersion($version);
        $this->assertProviderRegistered($reference->provider);

        return $this->registry->runtime($reference->provider)->resolve($reference);
    }

    /**
     * Validate metadata without resolving secret material (inventory / gate checks).
     *
     * @throws CredentialResolutionException
     */
    public function assertValidReferenceMetadata(Tenant $tenant, TenantSsoConfigVersion $version): void
    {
        $this->assertVersionAuthority($tenant, $version);
        $this->assertReferenceSource($version);
        $reference = $this->buildReferenceFromVersion($version);
        $this->assertProviderRegistered($reference->provider);
    }

    /**
     * Whether this version is configured for reference resolution (not legacy ciphertext).
     */
    public function usesReferenceSource(TenantSsoConfigVersion $version): bool
    {
        return ($version->credential_source ?? self::SOURCE_LEGACY_ENCRYPTED) === self::SOURCE_REFERENCE;
    }

    private function assertVersionAuthority(Tenant $tenant, TenantSsoConfigVersion $version): void
    {
        if ((string) $version->tenant_id !== (string) $tenant->id) {
            throw CredentialResolutionException::failClosed('tenant_scope_mismatch');
        }

        // Parent TenantSsoConfig must never be passed as credential authority.
        // Caller must supply TenantSsoConfigVersion only (type-enforced).
    }

    private function assertReferenceSource(TenantSsoConfigVersion $version): void
    {
        $source = $version->credential_source ?? self::SOURCE_LEGACY_ENCRYPTED;

        if ($source === self::SOURCE_LEGACY_ENCRYPTED) {
            throw CredentialResolutionException::failClosed('legacy_encrypted_source_not_resolved_here');
        }

        if ($source !== self::SOURCE_REFERENCE) {
            throw CredentialResolutionException::failClosed('unknown_credential_source');
        }

        if (blank($version->credential_provider) || blank($version->credential_reference) || blank($version->credential_type)) {
            throw CredentialResolutionException::failClosed('incomplete_reference_metadata');
        }

        if (($version->credential_status ?? null) === 'revoked') {
            throw CredentialResolutionException::failClosed('credential_revoked');
        }
    }

    private function buildReferenceFromVersion(TenantSsoConfigVersion $version): SecretReference
    {
        try {
            return SecretReference::fromVersionAttributes(
                (string) $version->credential_provider,
                (string) $version->credential_reference,
                (string) $version->credential_type,
                $version->credential_version_policy,
                $version->credential_environment_scope,
                $version->credential_status,
            );
        } catch (\InvalidArgumentException $e) {
            throw CredentialResolutionException::failClosed('invalid_reference_shape: '.$e->getMessage());
        }
    }

    private function assertProviderRegistered(string $providerKey): void
    {
        if (! $this->registry->hasRuntime($providerKey)) {
            throw CredentialResolutionException::failClosed('provider_not_registered:'.$providerKey);
        }
    }
}
