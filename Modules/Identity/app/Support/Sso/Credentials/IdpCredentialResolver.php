<?php

namespace Modules\Identity\Support\Sso\Credentials;

use Modules\Identity\Models\TenantSsoConfigVersion;
use Modules\Tenancy\Models\Tenant;

/**
 * Fail-closed IdP credential resolver for version-owned secret references.
 *
 * Reference-only: never reads database ciphertext.
 */
final class IdpCredentialResolver
{
    public const STATUS_ACTIVE = 'active';

    public function __construct(
        private readonly SecretProviderRegistry $registry,
    ) {}

    /**
     * Resolve a version-owned credential reference for an explicit protocol purpose.
     *
     * @param  string|null  $clientAuthMethod  When provided, must align with purpose allowlist.
     *
     * @throws CredentialResolutionException
     */
    public function resolveForVersion(
        Tenant $tenant,
        TenantSsoConfigVersion $version,
        CredentialPurpose $purpose,
        ?string $clientAuthMethod = null,
    ): SecretResolutionResult {
        $this->assertVersionAuthority($tenant, $version);
        $this->assertCompleteReferenceMetadata($version);
        $this->assertActiveStatus($version);
        $this->assertEnvironmentScope($version);
        $this->assertPurposeCompatibility($version, $purpose, $clientAuthMethod);

        $reference = $this->buildReferenceFromVersion($version);
        $this->assertProviderRegistered($reference->provider);

        return $this->registry->runtime($reference->provider)->resolve($reference);
    }

    /**
     * Validate metadata without resolving secret material.
     *
     * @throws CredentialResolutionException
     */
    public function assertValidReferenceMetadata(
        Tenant $tenant,
        TenantSsoConfigVersion $version,
        CredentialPurpose $purpose,
        ?string $clientAuthMethod = null,
    ): void {
        $this->assertVersionAuthority($tenant, $version);
        $this->assertCompleteReferenceMetadata($version);
        $this->assertActiveStatus($version);
        $this->assertEnvironmentScope($version);
        $this->assertPurposeCompatibility($version, $purpose, $clientAuthMethod);
        $reference = $this->buildReferenceFromVersion($version);
        $this->assertProviderRegistered($reference->provider);
    }

    private function assertVersionAuthority(Tenant $tenant, TenantSsoConfigVersion $version): void
    {
        if ((string) $version->tenant_id !== (string) $tenant->id) {
            throw CredentialResolutionException::failClosed('tenant_scope_mismatch');
        }
    }

    private function assertCompleteReferenceMetadata(TenantSsoConfigVersion $version): void
    {
        if (blank($version->credential_provider) || blank($version->credential_reference) || blank($version->credential_type)) {
            throw CredentialResolutionException::failClosed('incomplete_reference_metadata');
        }
    }

    private function assertActiveStatus(TenantSsoConfigVersion $version): void
    {
        $status = $version->credential_status;

        if ($status !== self::STATUS_ACTIVE) {
            throw CredentialResolutionException::failClosed('credential_not_active');
        }
    }

    private function assertEnvironmentScope(TenantSsoConfigVersion $version): void
    {
        $runtimeClass = config('identity.secrets.runtime_class');
        $known = config('identity.secrets.known_runtime_classes', []);

        if (! is_string($runtimeClass) || trim($runtimeClass) === '') {
            throw CredentialResolutionException::failClosed('missing_runtime_class');
        }

        $runtimeClass = strtolower(trim($runtimeClass));

        if (! is_array($known) || ! in_array($runtimeClass, array_map('strtolower', $known), true)) {
            throw CredentialResolutionException::failClosed('unknown_runtime_class');
        }

        $scope = $version->credential_environment_scope;
        if (! is_string($scope) || trim($scope) === '') {
            throw CredentialResolutionException::failClosed('missing_environment_scope');
        }

        $scope = strtolower(trim($scope));

        /** @var array<string, list<string>> $allowedByClass */
        $allowedByClass = config('identity.secrets.environment_scopes_by_runtime_class', []);
        $allowed = $allowedByClass[$runtimeClass] ?? [$runtimeClass];
        $allowed = array_map(static fn ($v) => strtolower((string) $v), $allowed);

        if (! in_array($scope, $allowed, true)) {
            throw CredentialResolutionException::failClosed('environment_mismatch');
        }

        if ($runtimeClass === 'production' && in_array($scope, ['local', 'development', 'test', 'controlled_uat'], true)) {
            throw CredentialResolutionException::failClosed('production_prohibited_credential_scope');
        }
    }

    private function assertPurposeCompatibility(
        TenantSsoConfigVersion $version,
        CredentialPurpose $purpose,
        ?string $clientAuthMethod,
    ): void {
        if (! $purpose->mayResolveSecret()) {
            throw CredentialResolutionException::failClosed('jwks_path_no_symmetric_credential');
        }

        $type = strtolower(trim((string) $version->credential_type));
        $allowedTypes = array_map('strtolower', $purpose->allowedCredentialTypes());

        if (! in_array($type, $allowedTypes, true)) {
            throw CredentialResolutionException::failClosed('credential_type_mismatch');
        }

        if ($clientAuthMethod !== null) {
            $method = strtolower(trim($clientAuthMethod));
            $allowedMethods = $purpose->allowedClientAuthMethods();
            if ($allowedMethods !== null) {
                $allowedMethods = array_map('strtolower', $allowedMethods);
                if (! in_array($method, $allowedMethods, true)) {
                    throw CredentialResolutionException::failClosed('credential_type_mismatch');
                }
            }
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
            throw CredentialResolutionException::failClosed('invalid_reference_shape');
        }
    }

    private function assertProviderRegistered(string $providerKey): void
    {
        if (! $this->registry->hasRuntime($providerKey)) {
            throw CredentialResolutionException::failClosed('provider_not_registered');
        }
    }
}
