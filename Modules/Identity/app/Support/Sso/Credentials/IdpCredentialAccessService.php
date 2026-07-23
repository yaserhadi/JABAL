<?php

namespace Modules\Identity\Support\Sso\Credentials;

use Illuminate\Support\Facades\Crypt;
use Modules\Identity\Exceptions\SsoSecurityException;
use Modules\Identity\Models\TenantSsoConfigVersion;
use Modules\Tenancy\Models\Tenant;

/**
 * BK-098 cutover facade: version-owned credential resolution with controlled source transition.
 *
 * - reference → IdpCredentialResolver only (no ciphertext fallback)
 * - legacy_encrypted → version-row Crypt decrypt only (never parent row)
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

        if ($source === IdpCredentialResolver::SOURCE_REFERENCE) {
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

        if ($source === IdpCredentialResolver::SOURCE_LEGACY_ENCRYPTED) {
            return $this->decryptLegacyVersionCiphertext($version);
        }

        throw CredentialResolutionException::failClosed('unknown_credential_source');
    }

    /**
     * Whether the version has usable credential material for operational gates (no plaintext returned).
     */
    public function versionHasUsableCredential(TenantSsoConfigVersion $version): bool
    {
        $source = $this->credentialSource($version);

        if ($source === IdpCredentialResolver::SOURCE_REFERENCE) {
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

        if ($source === IdpCredentialResolver::SOURCE_LEGACY_ENCRYPTED) {
            return filled($version->getAttributes()['client_secret_encrypted'] ?? null)
                && $version->secret_revoked_at === null;
        }

        return false;
    }

    /**
     * Fail closed if a reference version is incomplete before activation / operational use.
     *
     * @throws CredentialResolutionException
     */
    public function assertReferenceVersionReady(TenantSsoConfigVersion $version): void
    {
        if ($this->credentialSource($version) !== IdpCredentialResolver::SOURCE_REFERENCE) {
            return;
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

    public function credentialSource(TenantSsoConfigVersion $version): string
    {
        return (string) ($version->credential_source ?? IdpCredentialResolver::SOURCE_LEGACY_ENCRYPTED);
    }

    /**
     * @throws SsoSecurityException
     */
    private function decryptLegacyVersionCiphertext(TenantSsoConfigVersion $version): string
    {
        if ($version->secret_revoked_at !== null) {
            throw new SsoSecurityException('IdP credential has been revoked.');
        }

        $encrypted = $version->getAttributes()['client_secret_encrypted'] ?? null;
        if (! is_string($encrypted) || $encrypted === '') {
            throw new SsoSecurityException('Legacy IdP client secret is not configured on the version.');
        }

        try {
            $plain = Crypt::decryptString($encrypted);
        } catch (\Throwable) {
            throw new SsoSecurityException('Legacy IdP client secret could not be decrypted.');
        }

        if ($plain === '') {
            throw new SsoSecurityException('Legacy IdP client secret is empty.');
        }

        return $plain;
    }
}
