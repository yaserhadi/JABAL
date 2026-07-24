<?php

namespace Modules\Identity\Support\Sso\Credentials;

use Modules\Identity\Exceptions\SsoSecurityException;
use Modules\Identity\Models\TenantSsoConfigVersion;
use Modules\Tenancy\Models\Tenant;

/**
 * BK-098 readiness: version-owned credential resolution.
 *
 * - reference → IdpCredentialResolver only (no ciphertext fallback)
 * - legacy_encrypted → fail closed (not operational; demo data must be purged/reseeded)
 * - JWKS BC logout purpose must not be used to obtain a symmetric secret
 */
final class IdpCredentialAccessService
{
    public function __construct(
        private readonly IdpCredentialResolver $resolver,
        private readonly SecretProviderRegistry $registry,
    ) {}

    /**
     * Resolve the IdP client credential for a concrete config version + purpose.
     *
     * @throws SsoSecurityException
     * @throws CredentialResolutionException
     */
    public function resolveClientSecret(
        Tenant $tenant,
        TenantSsoConfigVersion $version,
        CredentialPurpose $purpose,
        ?string $clientAuthMethod = 'client_secret_post',
    ): string {
        if ((string) $version->tenant_id !== (string) $tenant->id) {
            throw new SsoSecurityException('IdP configuration version tenant mismatch.');
        }

        if (! $purpose->mayResolveSecret()) {
            throw CredentialResolutionException::failClosed('jwks_path_no_symmetric_credential');
        }

        $source = $this->credentialSource($version);

        if ($source === IdpCredentialResolver::SOURCE_LEGACY_ENCRYPTED) {
            throw CredentialResolutionException::failClosed('legacy_encrypted_not_operational');
        }

        if ($source !== IdpCredentialResolver::SOURCE_REFERENCE) {
            throw CredentialResolutionException::failClosed('unknown_credential_source');
        }

        $result = $this->resolver->resolveForVersion($tenant, $version, $purpose, $clientAuthMethod);
        if (! $result->ok) {
            throw CredentialResolutionException::failClosed($result->reason ?? 'reference_resolve_failed');
        }
        $value = $result->consumeValue();
        if ($value === null || $value === '') {
            throw CredentialResolutionException::failClosed('empty_resolved_value');
        }

        return $value;
    }

    /**
     * Whether the version has usable credential material for operational gates (no plaintext returned).
     */
    public function versionHasUsableCredential(TenantSsoConfigVersion $version): bool
    {
        $source = $this->credentialSource($version);

        if ($source !== IdpCredentialResolver::SOURCE_REFERENCE) {
            return false;
        }

        if (($version->credential_status ?? null) !== IdpCredentialResolver::STATUS_ACTIVE) {
            return false;
        }
        if (blank($version->credential_provider)
            || blank($version->credential_reference)
            || blank($version->credential_type)
            || blank($version->credential_environment_scope)) {
            return false;
        }
        if (! $this->registry->hasRuntime((string) $version->credential_provider)) {
            return false;
        }

        try {
            $ref = SecretReference::fromVersionAttributes(
                (string) $version->credential_provider,
                (string) $version->credential_reference,
                (string) $version->credential_type,
                $version->credential_version_policy,
                $version->credential_environment_scope,
                $version->credential_status,
            );

            return $this->registry->runtime($ref->provider)->exists($ref);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Fail closed if a reference version is incomplete before activation / operational use.
     * Legacy encrypted versions are never activatable.
     *
     * @throws CredentialResolutionException
     * @throws SsoSecurityException
     */
    public function assertOperationalCredentialReady(TenantSsoConfigVersion $version): void
    {
        $source = $this->credentialSource($version);

        if ($source === IdpCredentialResolver::SOURCE_LEGACY_ENCRYPTED) {
            throw new SsoSecurityException('Legacy encrypted credential versions cannot become operational.');
        }

        if ($source !== IdpCredentialResolver::SOURCE_REFERENCE) {
            throw CredentialResolutionException::failClosed('unknown_credential_source');
        }

        if (($version->credential_status ?? null) !== IdpCredentialResolver::STATUS_ACTIVE) {
            throw CredentialResolutionException::failClosed('credential_not_active');
        }
        if (blank($version->credential_provider)
            || blank($version->credential_reference)
            || blank($version->credential_type)
            || blank($version->credential_environment_scope)) {
            throw CredentialResolutionException::failClosed('incomplete_reference_metadata');
        }
        if (! $this->registry->hasRuntime((string) $version->credential_provider)) {
            throw CredentialResolutionException::failClosed('provider_not_registered');
        }
    }

    /**
     * @deprecated Use assertOperationalCredentialReady()
     */
    public function assertReferenceVersionReady(TenantSsoConfigVersion $version): void
    {
        $this->assertOperationalCredentialReady($version);
    }

    public function credentialSource(TenantSsoConfigVersion $version): string
    {
        return (string) ($version->credential_source ?? IdpCredentialResolver::SOURCE_LEGACY_ENCRYPTED);
    }
}
