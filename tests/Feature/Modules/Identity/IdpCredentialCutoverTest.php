<?php

namespace Tests\Feature\Modules\Identity;

use Modules\Identity\Models\TenantSsoConfig;
use Modules\Identity\Models\TenantSsoConfigVersion;
use Modules\Identity\Services\SsoConfigService;
use Modules\Identity\Support\Sso\Credentials\CredentialPurpose;
use Modules\Identity\Support\Sso\Credentials\CredentialResolutionException;
use Modules\Identity\Support\Sso\Credentials\IdpCredentialAccessService;
use Modules\Tenancy\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\GrantsSsoEntitlement;
use Tests\TestCase;

/** BK-098 — reference-only credential access (legacy model eradicated). */
class IdpCredentialCutoverTest extends TestCase
{
    use GrantsSsoEntitlement;

    #[Test]
    public function reference_versions_resolve_via_provider(): void
    {
        [$tenant, $version] = $this->provisionViaService('sealed-secret-value');

        tenancy()->initialize($tenant);
        try {
            $access = app(IdpCredentialAccessService::class);
            $plain = $access->resolveClientSecret(
                $tenant,
                $version->fresh(),
                CredentialPurpose::OidcClientAuth,
            );
            $this->assertSame('sealed-secret-value', $plain);

            app(\Modules\Identity\Support\Sso\Credentials\SecretProviderRegistry::class)
                ->management('local_sealed')
                ->revoke(new \Modules\Identity\Support\Sso\Credentials\SecretReference(
                    'local_sealed',
                    (string) $version->credential_reference,
                    'oidc_client_secret',
                    'current',
                    'test',
                    'active',
                ));
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
        [$tenant, $version] = $this->provisionViaService('sealed-secret-value');

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
    public function plaintext_secret_update_provisions_new_reference_version(): void
    {
        [$tenant] = $this->provisionViaService('sealed-secret-value');
        $service = app(SsoConfigService::class);

        tenancy()->initialize($tenant);
        try {
            $before = $service->getActiveVersionId($tenant);
            $service->update($tenant, [
                'client_secret' => 'rotated-secret-value',
            ]);
            $after = $service->getActiveVersionId($tenant);
            $this->assertNotSame($before, $after);
            $active = TenantSsoConfigVersion::query()->findOrFail($after);
            $this->assertSame('local_sealed', $active->credential_provider);
            $this->assertSame('rotated-secret-value', $service->resolveClientSecretForTenant($tenant));
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
                'client_secret' => 'bootstrap',
            ]);
            $configId = TenantSsoConfig::query()->where('tenant_id', $tenant->id)->value('id');
            $draft = TenantSsoConfigVersion::query()->create([
                'tenant_id' => $tenant->id,
                'config_id' => $configId,
                'version_number' => 90,
                'status' => TenantSsoConfigVersion::STATUS_APPROVED,
                'issuer_url' => 'https://idp.example.com',
                'client_id' => 'client-v1',
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
    public function material_update_preserves_active_reference_metadata(): void
    {
        [$tenant, $version] = $this->provisionViaService('sealed-secret-value');
        $service = app(SsoConfigService::class);

        tenancy()->initialize($tenant);
        try {
            $service->update($tenant, [
                'provider_label' => 'Updated Label',
            ]);
            $activeId = $service->getActiveVersionId($tenant);
            $active = TenantSsoConfigVersion::query()->findOrFail($activeId);
            $this->assertSame($version->credential_reference, $active->credential_reference);
            $this->assertSame(
                'sealed-secret-value',
                $service->resolveClientSecretForTenant($tenant),
            );
        } finally {
            tenancy()->end();
        }
    }

    /**
     * @return array{0: Tenant, 1: TenantSsoConfigVersion}
     */
    private function provisionViaService(string $secret): array
    {
        $tenant = Tenant::factory()->create();
        $this->grantSsoAvailable($tenant);
        $service = app(SsoConfigService::class);

        tenancy()->initialize($tenant);
        $service->update($tenant, [
            'enabled' => true,
            'issuer_url' => 'https://idp.example.com',
            'client_id' => 'client-v1',
            'client_secret' => $secret,
        ]);
        $version = TenantSsoConfigVersion::query()->findOrFail($service->getActiveVersionId($tenant));
        tenancy()->end();

        return [$tenant, $version];
    }
}
