<?php

namespace Tests\Feature\Modules\Identity;

use LogicException;
use Modules\Identity\Models\TenantSsoConfig;
use Modules\Identity\Models\TenantSsoConfigVersion;
use Modules\Identity\Services\SsoConfigService;
use Modules\Identity\Support\Sso\Credentials\CredentialResolutionException;
use Modules\Identity\Support\Sso\Credentials\IdpCredentialResolver;
use Modules\Identity\Support\Sso\Credentials\SecretProviderManagement;
use Modules\Identity\Support\Sso\Credentials\SecretProviderRegistry;
use Modules\Identity\Support\Sso\Credentials\SecretProviderRuntime;
use Modules\Identity\Support\Sso\Credentials\SecretReference;
use Modules\Identity\Support\Sso\Credentials\SecretResolutionResult;
use Modules\Tenancy\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\GrantsSsoEntitlement;
use Tests\TestCase;

/** BK-098 — Neutral credential reference contract + persistence foundation. */
class IdpCredentialReferenceFoundationTest extends TestCase
{
    use GrantsSsoEntitlement;

    #[Test]
    public function new_versions_default_to_legacy_encrypted_credential_source(): void
    {
        $tenant = Tenant::factory()->create();
        $this->grantSsoAvailable($tenant);
        $service = app(SsoConfigService::class);

        tenancy()->initialize($tenant);
        try {
            $service->update($tenant, [
                'enabled' => true,
                'issuer_url' => 'https://idp.example.com',
                'client_id' => 'client-v1',
                'client_secret' => 'secret-v1',
            ]);

            $version = TenantSsoConfigVersion::query()->findOrFail($service->getActiveVersionId($tenant));
            $this->assertSame(TenantSsoConfigVersion::CREDENTIAL_SOURCE_LEGACY_ENCRYPTED, $version->credential_source);
            $this->assertNull($version->credential_provider);
            $this->assertNull($version->credential_reference);

            $parent = TenantSsoConfig::query()->where('tenant_id', $tenant->id)->firstOrFail();
            $this->assertFalse(array_key_exists('credential_provider', $parent->getAttributes()));
            $this->assertFalse(array_key_exists('credential_reference', $parent->getAttributes()));
        } finally {
            tenancy()->end();
        }
    }

    #[Test]
    public function reference_material_fields_are_immutable_after_draft(): void
    {
        $tenant = Tenant::factory()->create();
        $this->grantSsoAvailable($tenant);
        $service = app(SsoConfigService::class);

        tenancy()->initialize($tenant);
        try {
            $service->update($tenant, [
                'enabled' => true,
                'issuer_url' => 'https://idp.example.com',
                'client_id' => 'client-v1',
                'client_secret' => 'secret-v1',
            ]);

            $version = TenantSsoConfigVersion::query()->findOrFail($service->getActiveVersionId($tenant));
            $this->expectException(LogicException::class);
            $version->update([
                'credential_source' => TenantSsoConfigVersion::CREDENTIAL_SOURCE_REFERENCE,
                'credential_provider' => 'local_sealed',
                'credential_reference' => 'enterprise-sso/t/v/client-secret',
                'credential_type' => 'oidc_client_secret',
            ]);
        } finally {
            tenancy()->end();
        }
    }

    #[Test]
    public function non_secret_verification_metadata_may_update_on_active_version(): void
    {
        $tenant = Tenant::factory()->create();
        $this->grantSsoAvailable($tenant);
        $service = app(SsoConfigService::class);

        tenancy()->initialize($tenant);
        try {
            $service->update($tenant, [
                'enabled' => true,
                'issuer_url' => 'https://idp.example.com',
                'client_id' => 'client-v1',
                'client_secret' => 'secret-v1',
            ]);

            $version = TenantSsoConfigVersion::query()->findOrFail($service->getActiveVersionId($tenant));
            $version->forceFill([
                'credential_last_verified_at' => now(),
                'credential_status' => 'active',
            ])->save();

            $this->assertNotNull($version->fresh()->credential_last_verified_at);
            $this->assertSame('active', $version->fresh()->credential_status);
        } finally {
            tenancy()->end();
        }
    }

    #[Test]
    public function resolver_fails_closed_on_legacy_source_without_ciphertext_fallback(): void
    {
        $tenant = Tenant::factory()->create();
        $this->grantSsoAvailable($tenant);
        $service = app(SsoConfigService::class);
        $resolver = app(IdpCredentialResolver::class);

        tenancy()->initialize($tenant);
        try {
            $service->update($tenant, [
                'enabled' => true,
                'issuer_url' => 'https://idp.example.com',
                'client_id' => 'client-v1',
                'client_secret' => 'secret-v1',
            ]);

            $version = TenantSsoConfigVersion::query()->findOrFail($service->getActiveVersionId($tenant));
            $this->assertTrue(filled($version->getAttributes()['client_secret_encrypted'] ?? null));

            try {
                $resolver->resolveForVersion($tenant, $version);
                $this->fail('Expected CredentialResolutionException');
            } catch (CredentialResolutionException $e) {
                $this->assertStringContainsString('legacy_encrypted_source_not_resolved_here', $e->getMessage());
            }
        } finally {
            tenancy()->end();
        }
    }

    #[Test]
    public function resolver_fails_closed_when_provider_not_registered(): void
    {
        $tenant = Tenant::factory()->create();
        $this->grantSsoAvailable($tenant);
        $service = app(SsoConfigService::class);
        $resolver = app(IdpCredentialResolver::class);

        tenancy()->initialize($tenant);
        try {
            $service->update($tenant, [
                'enabled' => true,
                'issuer_url' => 'https://idp.example.com',
                'client_id' => 'client-v1',
                'client_secret' => 'secret-v1',
            ]);

            $draft = TenantSsoConfigVersion::query()->create([
                'tenant_id' => $tenant->id,
                'config_id' => TenantSsoConfig::query()->where('tenant_id', $tenant->id)->value('id'),
                'version_number' => 99,
                'status' => TenantSsoConfigVersion::STATUS_DRAFT,
                'issuer_url' => 'https://idp.example.com',
                'client_id' => 'client-v1',
                'credential_source' => TenantSsoConfigVersion::CREDENTIAL_SOURCE_REFERENCE,
                'credential_provider' => 'local_sealed',
                'credential_reference' => 'enterprise-sso/'.$tenant->id.'/v99/client-secret',
                'credential_type' => 'oidc_client_secret',
                'credential_status' => 'active',
            ]);

            $this->expectException(CredentialResolutionException::class);
            $this->expectExceptionMessage('provider_not_registered:local_sealed');
            $resolver->resolveForVersion($tenant, $draft);
        } finally {
            tenancy()->end();
        }
    }

    #[Test]
    public function resolver_enforces_tenant_scope_and_rejects_revoked(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $this->grantSsoAvailable($tenantA);
        $service = app(SsoConfigService::class);
        $resolver = app(IdpCredentialResolver::class);
        $registry = app(SecretProviderRegistry::class);
        $registry->registerRuntime(new class implements SecretProviderRuntime
        {
            public function providerKey(): string
            {
                return 'local_sealed';
            }

            public function exists(SecretReference $reference): bool
            {
                return true;
            }

            public function metadata(SecretReference $reference): array
            {
                return ['status' => 'active'];
            }

            public function resolve(SecretReference $reference): SecretResolutionResult
            {
                return SecretResolutionResult::success('test-only-value');
            }
        });

        tenancy()->initialize($tenantA);
        try {
            $service->update($tenantA, [
                'enabled' => true,
                'issuer_url' => 'https://idp.example.com',
                'client_id' => 'client-v1',
                'client_secret' => 'secret-v1',
            ]);

            $configId = TenantSsoConfig::query()->where('tenant_id', $tenantA->id)->value('id');
            $version = TenantSsoConfigVersion::query()->create([
                'tenant_id' => $tenantA->id,
                'config_id' => $configId,
                'version_number' => 50,
                'status' => TenantSsoConfigVersion::STATUS_DRAFT,
                'issuer_url' => 'https://idp.example.com',
                'client_id' => 'client-v1',
                'credential_source' => TenantSsoConfigVersion::CREDENTIAL_SOURCE_REFERENCE,
                'credential_provider' => 'local_sealed',
                'credential_reference' => 'enterprise-sso/'.$tenantA->id.'/v50/client-secret',
                'credential_type' => 'oidc_client_secret',
                'credential_status' => 'active',
            ]);

            try {
                $resolver->resolveForVersion($tenantB, $version);
                $this->fail('Expected tenant_scope_mismatch');
            } catch (CredentialResolutionException $e) {
                $this->assertStringContainsString('tenant_scope_mismatch', $e->getMessage());
            }

            $version->forceFill(['credential_status' => 'revoked'])->save();
            try {
                $resolver->resolveForVersion($tenantA, $version->fresh());
                $this->fail('Expected credential_revoked');
            } catch (CredentialResolutionException $e) {
                $this->assertStringContainsString('credential_revoked', $e->getMessage());
            }
        } finally {
            tenancy()->end();
        }
    }

    #[Test]
    public function registry_keeps_runtime_and_management_separate(): void
    {
        $registry = new SecretProviderRegistry;
        $runtime = new class implements SecretProviderRuntime
        {
            public function providerKey(): string
            {
                return 'stub';
            }

            public function exists(SecretReference $reference): bool
            {
                return false;
            }

            public function metadata(SecretReference $reference): array
            {
                return [];
            }

            public function resolve(SecretReference $reference): SecretResolutionResult
            {
                return SecretResolutionResult::failure('not_implemented');
            }
        };
        $management = new class implements SecretProviderManagement
        {
            public function providerKey(): string
            {
                return 'stub';
            }

            public function provision(SecretReference $reference, string $plaintext): void {}

            public function rotate(SecretReference $reference, string $plaintext): void {}

            public function revoke(SecretReference $reference): void {}
        };

        $registry->registerRuntime($runtime);
        $this->assertTrue($registry->hasRuntime('stub'));
        $this->assertSame(['stub'], $registry->registeredRuntimeKeys());

        try {
            $registry->management('stub');
            $this->fail('Management must not be available until registered');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('Unregistered secret provider management', $e->getMessage());
        }

        $registry->registerManagement($management);
        $this->assertSame('stub', $registry->management('stub')->providerKey());
        $this->assertNotSame($registry->runtime('stub'), $registry->management('stub'));
    }

    #[Test]
    public function successful_resolve_requires_registered_runtime_and_reference_source(): void
    {
        $tenant = Tenant::factory()->create();
        $this->grantSsoAvailable($tenant);
        $service = app(SsoConfigService::class);
        $registry = app(SecretProviderRegistry::class);
        $registry->registerRuntime(new class implements SecretProviderRuntime
        {
            public function providerKey(): string
            {
                return 'local_sealed';
            }

            public function exists(SecretReference $reference): bool
            {
                return true;
            }

            public function metadata(SecretReference $reference): array
            {
                return ['status' => 'active'];
            }

            public function resolve(SecretReference $reference): SecretResolutionResult
            {
                return SecretResolutionResult::success('resolved-from-stub');
            }
        });
        $resolver = new IdpCredentialResolver($registry);

        tenancy()->initialize($tenant);
        try {
            $service->update($tenant, [
                'enabled' => true,
                'issuer_url' => 'https://idp.example.com',
                'client_id' => 'client-v1',
                'client_secret' => 'secret-v1',
            ]);

            $configId = TenantSsoConfig::query()->where('tenant_id', $tenant->id)->value('id');
            $version = TenantSsoConfigVersion::query()->create([
                'tenant_id' => $tenant->id,
                'config_id' => $configId,
                'version_number' => 77,
                'status' => TenantSsoConfigVersion::STATUS_DRAFT,
                'issuer_url' => 'https://idp.example.com',
                'client_id' => 'client-v1',
                'credential_source' => TenantSsoConfigVersion::CREDENTIAL_SOURCE_REFERENCE,
                'credential_provider' => 'local_sealed',
                'credential_reference' => 'enterprise-sso/'.$tenant->id.'/v77/client-secret',
                'credential_type' => 'oidc_client_secret',
                'credential_status' => 'active',
            ]);

            $result = $resolver->resolveForVersion($tenant, $version);
            $this->assertTrue($result->ok);
            $this->assertSame('resolved-from-stub', $result->consumeValue());
        } finally {
            tenancy()->end();
        }
    }
}
