<?php

namespace Tests\Feature\Modules\Identity;

use Modules\Identity\Models\TenantSsoConfigVersion;
use Modules\Identity\Services\SsoConfigService;
use Modules\Identity\Support\Sso\Credentials\CredentialPurpose;
use Modules\Identity\Support\Sso\Credentials\IdpCredentialAccessService;
use Modules\Identity\Support\Sso\Credentials\SecretProviderRegistry;
use Modules\Identity\Support\Sso\Credentials\SecretReference;
use Modules\Tenancy\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\GrantsSsoEntitlement;
use Tests\TestCase;

/** BK-098 — local_sealed provision/resolve/rotate/revoke + reference-only schema. */
class IdpCredentialReadinessTest extends TestCase
{
    use GrantsSsoEntitlement;

    #[Test]
    public function provision_resolve_rotate_revoke_reference_only(): void
    {
        $tenant = Tenant::factory()->create();
        $this->grantSsoAvailable($tenant);
        $service = app(SsoConfigService::class);
        $access = app(IdpCredentialAccessService::class);
        $registry = app(SecretProviderRegistry::class);

        tenancy()->initialize($tenant);
        try {
            $service->update($tenant, [
                'enabled' => true,
                'issuer_url' => 'https://idp.example.com',
                'client_id' => 'client-a',
                'client_secret' => 'initial-secret',
            ]);

            $v1 = TenantSsoConfigVersion::query()->findOrFail($service->getActiveVersionId($tenant));
            $this->assertSame('local_sealed', $v1->credential_provider);
            $this->assertArrayNotHasKey('client_secret_encrypted', $v1->getAttributes());
            $this->assertSame(
                'initial-secret',
                $access->resolveClientSecret($tenant, $v1, CredentialPurpose::OidcClientAuth),
            );

            $service->update($tenant, ['client_secret' => 'rotated-secret']);
            $v2 = TenantSsoConfigVersion::query()->findOrFail($service->getActiveVersionId($tenant));
            $this->assertNotSame($v1->id, $v2->id);
            $this->assertSame(
                'rotated-secret',
                $access->resolveClientSecret($tenant, $v2, CredentialPurpose::OidcClientAuth),
            );

            $ref = new SecretReference(
                'local_sealed',
                (string) $v2->credential_reference,
                'oidc_client_secret',
                'current',
                'test',
                'active',
            );
            $registry->management('local_sealed')->rotate($ref, 'post-rotate-secret');
            $this->assertSame(
                'post-rotate-secret',
                $access->resolveClientSecret($tenant, $v2->fresh(), CredentialPurpose::OidcClientAuth),
            );

            $registry->management('local_sealed')->revoke($ref);
            $v2->forceFill(['credential_status' => 'revoked'])->save();
            $this->assertNull($service->resolveClientSecretForTenant($tenant));
        } finally {
            tenancy()->end();
        }
    }

    #[Test]
    public function tenant_isolation_prevents_cross_tenant_resolve(): void
    {
        $a = Tenant::factory()->create();
        $b = Tenant::factory()->create();
        $this->grantSsoAvailable($a);
        $this->grantSsoAvailable($b);
        $service = app(SsoConfigService::class);

        tenancy()->initialize($a);
        $service->update($a, [
            'enabled' => true,
            'issuer_url' => 'https://idp.example.com',
            'client_id' => 'client-a',
            'client_secret' => 'secret-a',
        ]);
        $versionA = TenantSsoConfigVersion::query()->findOrFail($service->getActiveVersionId($a));
        tenancy()->end();

        tenancy()->initialize($b);
        try {
            $this->expectException(\Modules\Identity\Exceptions\SsoSecurityException::class);
            app(IdpCredentialAccessService::class)->resolveClientSecret(
                $b,
                $versionA,
                CredentialPurpose::OidcClientAuth,
            );
        } finally {
            tenancy()->end();
        }
    }

    #[Test]
    public function production_runtime_class_denies_local_sealed_resolve(): void
    {
        $tenant = Tenant::factory()->create();
        $this->grantSsoAvailable($tenant);
        $service = app(SsoConfigService::class);

        tenancy()->initialize($tenant);
        try {
            $service->update($tenant, [
                'enabled' => true,
                'issuer_url' => 'https://idp.example.com',
                'client_id' => 'client-a',
                'client_secret' => 'secret-a',
            ]);
            $version = TenantSsoConfigVersion::query()->findOrFail($service->getActiveVersionId($tenant));

            config(['identity.secrets.runtime_class' => 'production']);

            $this->expectException(\Modules\Identity\Support\Sso\Credentials\CredentialResolutionException::class);
            app(IdpCredentialAccessService::class)->resolveClientSecret(
                $tenant,
                $version,
                CredentialPurpose::OidcClientAuth,
            );
        } finally {
            tenancy()->end();
        }
    }
}
