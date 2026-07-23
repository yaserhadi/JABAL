<?php

namespace Tests\Feature\Modules\Identity;

use Modules\Identity\Models\TenantSsoConfig;
use Modules\Identity\Models\TenantSsoConfigVersion;
use Modules\Identity\Services\SsoConfigService;
use Modules\Identity\Support\Sso\Credentials\CredentialPurpose;
use Modules\Identity\Support\Sso\Credentials\CredentialResolutionException;
use Modules\Identity\Support\Sso\Credentials\IdpCredentialAccessService;
use Modules\Identity\Support\Sso\Credentials\LocalSealed\LocalSealedEngine;
use Modules\Identity\Support\Sso\Credentials\LocalSealed\LocalSealedKeySource;
use Modules\Identity\Support\Sso\Credentials\LocalSealed\LocalSealedManagement;
use Modules\Identity\Support\Sso\Credentials\LocalSealed\LocalSealedPathResolver;
use Modules\Identity\Support\Sso\Credentials\LocalSealed\LocalSealedRuntime;
use Modules\Identity\Support\Sso\Credentials\SecretProviderRegistry;
use Modules\Identity\Support\Sso\Credentials\SecretReference;
use Modules\Tenancy\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\GrantsSsoEntitlement;
use Tests\TestCase;

/** BK-098 — credential consumer cutover (no auto-migrate; no ciphertext fallback). */
class IdpCredentialCutoverTest extends TestCase
{
    use GrantsSsoEntitlement;

    private string $base;

    private string $storeDir;

    private string $keyFile;

    private string $publicDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->base = storage_path('framework/testing/cutover_'.bin2hex(random_bytes(4)));
        $this->storeDir = $this->base.DIRECTORY_SEPARATOR.'store';
        $this->publicDir = $this->base.DIRECTORY_SEPARATOR.'public';
        $this->keyFile = $this->base.DIRECTORY_SEPARATOR.'unseal.key';
        mkdir($this->storeDir, 0700, true);
        mkdir($this->publicDir, 0700, true);
        file_put_contents($this->keyFile, base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES)));

        config([
            'identity.secrets.runtime_class' => 'test',
            'identity.secrets.production_state_active' => false,
            'identity.secrets.allowed_runtime_classes_for_local_sealed' => [
                'local', 'development', 'test', 'controlled_uat',
            ],
        ]);
    }

    protected function tearDown(): void
    {
        $this->deleteTree($this->base);
        parent::tearDown();
    }

    #[Test]
    public function legacy_encrypted_versions_resolve_without_parent_ciphertext(): void
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
                'client_secret' => 'legacy-secret-value',
            ]);

            $version = TenantSsoConfigVersion::query()->findOrFail($service->getActiveVersionId($tenant));
            $this->assertSame('legacy_encrypted', $version->credential_source);

            // Clear parent ciphertext — operational resolve must still work via version.
            TenantSsoConfig::query()->where('tenant_id', $tenant->id)->update([
                'client_secret_encrypted' => null,
            ]);

            $plain = $service->getDecryptedClientSecret($tenant);
            $this->assertSame('legacy-secret-value', $plain);
            $this->assertSame(
                'legacy-secret-value',
                $service->getDecryptedClientSecretForVersion($tenant, $version),
            );
        } finally {
            tenancy()->end();
        }
    }

    #[Test]
    public function reference_versions_resolve_via_provider_with_zero_ciphertext_fallback(): void
    {
        $this->registerLocalSealed();
        [$tenant, $version, $ref] = $this->provisionReferenceVersion('sealed-secret-value');

        tenancy()->initialize($tenant);
        try {
            // Even with leftover ciphertext, reference path must not use it.
            \Illuminate\Support\Facades\DB::connection('tenant')
                ->table('tenant_sso_config_versions')
                ->where('id', $version->id)
                ->update([
                    'client_secret_encrypted' => \Illuminate\Support\Facades\Crypt::encryptString('ciphertext-must-not-win'),
                ]);
            $version = $version->fresh();

            $access = app(IdpCredentialAccessService::class);
            $plain = $access->resolveClientSecret(
                $tenant,
                $version->fresh(),
                CredentialPurpose::OidcClientAuth,
            );
            $this->assertSame('sealed-secret-value', $plain);

            // Fail closed: revoke sealed + status, leftover ciphertext ignored.
            app(SecretProviderRegistry::class)->management('local_sealed')->revoke($ref);
            $version->forceFill(['credential_status' => 'revoked'])->save();

            try {
                $access->resolveClientSecret($tenant, $version->fresh(), CredentialPurpose::OidcClientAuth);
                $this->fail('expected fail closed');
            } catch (CredentialResolutionException $e) {
                $this->assertStringContainsString('credential_not_active', $e->getMessage());
            }
        } finally {
            tenancy()->end();
        }
    }

    #[Test]
    public function jwks_backchannel_purpose_does_not_resolve_symmetric_secret(): void
    {
        $this->registerLocalSealed();
        [$tenant, $version] = $this->provisionReferenceVersion('sealed-secret-value');

        tenancy()->initialize($tenant);
        try {
            $this->expectException(CredentialResolutionException::class);
            $this->expectExceptionMessage('jwks_path_no_symmetric_credential');
            app(IdpCredentialAccessService::class)->resolveClientSecret(
                $tenant,
                $version,
                CredentialPurpose::BackchannelLogoutJwks,
            );
        } finally {
            tenancy()->end();
        }
    }

    #[Test]
    public function plaintext_secret_write_rejected_when_active_version_is_reference(): void
    {
        $this->registerLocalSealed();
        [$tenant] = $this->provisionReferenceVersion('sealed-secret-value');
        $service = app(SsoConfigService::class);

        tenancy()->initialize($tenant);
        try {
            try {
                $service->update($tenant, [
                    'client_secret' => 'new-plaintext-forbidden',
                ]);
                $this->fail('expected 422');
            } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
                $this->assertSame(422, $e->getStatusCode());
            }
        } finally {
            tenancy()->end();
        }
    }

    #[Test]
    public function incomplete_reference_version_cannot_activate(): void
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
                'client_secret' => 'legacy-bootstrap',
            ]);
            $configId = TenantSsoConfig::query()->where('tenant_id', $tenant->id)->value('id');
            $draft = TenantSsoConfigVersion::query()->create([
                'tenant_id' => $tenant->id,
                'config_id' => $configId,
                'version_number' => 90,
                'status' => TenantSsoConfigVersion::STATUS_APPROVED,
                'issuer_url' => 'https://idp.example.com',
                'client_id' => 'client-v1',
                'credential_source' => TenantSsoConfigVersion::CREDENTIAL_SOURCE_REFERENCE,
                'credential_provider' => null,
                'credential_reference' => null,
                'credential_type' => null,
                'credential_environment_scope' => null,
                'credential_status' => 'active',
                'approved_at' => now(),
            ]);

            $this->expectException(CredentialResolutionException::class);
            $this->expectExceptionMessage('incomplete_reference_metadata');
            app(\Modules\Identity\Services\SsoConfigGovernanceService::class)
                ->activateVersion($tenant, (string) $draft->id);
        } finally {
            tenancy()->end();
        }
    }

    #[Test]
    public function material_update_preserves_active_reference_credential_source(): void
    {
        $this->registerLocalSealed();
        [$tenant, $version] = $this->provisionReferenceVersion('sealed-secret-value');
        $service = app(SsoConfigService::class);

        tenancy()->initialize($tenant);
        try {
            $service->update($tenant, [
                'provider_label' => 'Updated Label',
            ]);
            $activeId = $service->getActiveVersionId($tenant);
            $active = TenantSsoConfigVersion::query()->findOrFail($activeId);
            $this->assertSame(TenantSsoConfigVersion::CREDENTIAL_SOURCE_REFERENCE, $active->credential_source);
            $this->assertSame($version->credential_reference, $active->credential_reference);
            $this->assertNull($active->getAttributes()['client_secret_encrypted'] ?? null);
            $this->assertSame(
                'sealed-secret-value',
                $service->getDecryptedClientSecret($tenant),
            );
        } finally {
            tenancy()->end();
        }
    }

    private function registerLocalSealed(): void
    {
        $registry = app(SecretProviderRegistry::class);
        if ($registry->isSealed()) {
            $registry->unsealForTesting();
        }
        if ($registry->hasRuntime('local_sealed')) {
            $registry->seal();

            return;
        }

        $engine = new LocalSealedEngine(
            new LocalSealedPathResolver($this->storeDir, $this->publicDir),
            new LocalSealedKeySource($this->keyFile, $this->storeDir, $this->publicDir),
            'test',
            ['local', 'development', 'test', 'controlled_uat'],
        );
        $registry->registerRuntime(new LocalSealedRuntime($engine));
        $registry->registerManagement(new LocalSealedManagement($engine));
        $registry->seal();
    }

    /**
     * @return array{0: Tenant, 1: TenantSsoConfigVersion, 2: SecretReference}
     */
    private function provisionReferenceVersion(string $secret): array
    {
        $tenant = Tenant::factory()->create();
        $this->grantSsoAvailable($tenant);
        $service = app(SsoConfigService::class);

        tenancy()->initialize($tenant);
        $service->update($tenant, [
            'enabled' => true,
            'issuer_url' => 'https://idp.example.com',
            'client_id' => 'client-v1',
            'client_secret' => 'bootstrap-legacy',
        ]);

        $configId = TenantSsoConfig::query()->where('tenant_id', $tenant->id)->value('id');
        $logical = 'enterprise-sso/'.$tenant->id.'/cutover/client-secret';
        $ref = new SecretReference('local_sealed', $logical, 'oidc_client_secret', 'current', 'test', 'active');
        app(SecretProviderRegistry::class)->management('local_sealed')->provision($ref, $secret);

        $version = TenantSsoConfigVersion::query()->create([
            'tenant_id' => $tenant->id,
            'config_id' => $configId,
            'version_number' => 50,
            'status' => TenantSsoConfigVersion::STATUS_ACTIVE,
            'issuer_url' => 'https://idp.example.com',
            'client_id' => 'client-v1',
            'credential_source' => TenantSsoConfigVersion::CREDENTIAL_SOURCE_REFERENCE,
            'credential_provider' => 'local_sealed',
            'credential_reference' => $logical,
            'credential_type' => 'oidc_client_secret',
            'credential_environment_scope' => 'test',
            'credential_status' => 'active',
            'activated_at' => now(),
        ]);

        TenantSsoConfig::query()->whereKey($configId)->update([
            'active_version_id' => $version->id,
        ]);
        tenancy()->end();

        return [$tenant, $version, $ref];
    }

    private function deleteTree(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            if ($item->isLink() || $item->isFile()) {
                @unlink($item->getPathname());
            } else {
                @rmdir($item->getPathname());
            }
        }
        @rmdir($dir);
    }
}
