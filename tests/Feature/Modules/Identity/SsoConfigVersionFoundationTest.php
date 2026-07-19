<?php

namespace Tests\Feature\Modules\Identity;

use LogicException;
use Modules\Identity\Models\TenantSsoConfigVersion;
use Modules\Identity\Services\SsoConfigService;
use Modules\Tenancy\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\GrantsSsoEntitlement;
use Tests\TestCase;

/** BK-082 — IdP configuration version foundation (DEC-0024 D15/D30 minimum). */
class SsoConfigVersionFoundationTest extends TestCase
{
    use GrantsSsoEntitlement;

    #[Test]
    public function create_and_material_update_produce_immutable_active_versions(): void
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

            $v1Id = $service->getActiveVersionId($tenant);
            $this->assertNotNull($v1Id);
            $this->assertSame(1, TenantSsoConfigVersion::query()->count());

            $service->update($tenant, [
                'issuer_url' => 'https://idp.example.com/v2',
                'client_id' => 'client-v2',
            ]);

            $v2Id = $service->getActiveVersionId($tenant);
            $this->assertNotNull($v2Id);
            $this->assertNotSame($v1Id, $v2Id);
            $this->assertSame(2, TenantSsoConfigVersion::query()->count());

            $v1 = TenantSsoConfigVersion::query()->findOrFail($v1Id);
            $v2 = TenantSsoConfigVersion::query()->findOrFail($v2Id);
            $this->assertSame(TenantSsoConfigVersion::STATUS_SUPERSEDED, $v1->status);
            $this->assertSame(TenantSsoConfigVersion::STATUS_ACTIVE, $v2->status);
            $this->assertSame('https://idp.example.com/v2', $v2->issuer_url);
            $this->assertSame('client-v2', $v2->client_id);

            $this->expectException(LogicException::class);
            $v2->update(['issuer_url' => 'https://evil.example']);
        } finally {
            tenancy()->end();
        }
    }

    #[Test]
    public function entitlement_flag_changes_do_not_create_new_versions(): void
    {
        $tenant = Tenant::factory()->create();
        $this->grantSsoAvailable($tenant);
        $service = app(SsoConfigService::class);

        tenancy()->initialize($tenant);
        try {
            $service->update($tenant, [
                'enabled' => true,
                'issuer_url' => 'https://idp.example.com',
                'client_id' => 'client-id',
                'client_secret' => 'secret',
            ]);
            $versionId = $service->getActiveVersionId($tenant);
            $this->assertSame(1, TenantSsoConfigVersion::query()->count());

            $service->disableForEntitlementLoss($tenant);
            $this->assertSame($versionId, $service->getActiveVersionId($tenant));
            $this->assertSame(1, TenantSsoConfigVersion::query()->count());

            $service->clearEntitlementDisableFlag($tenant);
            $this->assertSame($versionId, $service->getActiveVersionId($tenant));
            $this->assertSame(1, TenantSsoConfigVersion::query()->count());
        } finally {
            tenancy()->end();
        }
    }

    #[Test]
    public function find_version_for_tenant_is_tenant_scoped(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $this->grantSsoAvailable($tenantA);
        $this->grantSsoAvailable($tenantB);
        $service = app(SsoConfigService::class);

        tenancy()->initialize($tenantA);
        $service->update($tenantA, [
            'enabled' => true,
            'issuer_url' => 'https://idp-a.example.com',
            'client_id' => 'a',
            'client_secret' => 'secret-a',
        ]);
        $versionA = $service->getActiveVersionId($tenantA);
        tenancy()->end();

        tenancy()->initialize($tenantB);
        $this->assertNull($service->findVersionForTenant($tenantB, (string) $versionA));
        $found = $service->findVersionForTenant($tenantA, (string) $versionA);
        tenancy()->end();

        $this->assertNotNull($found);
        $this->assertSame($versionA, $found->id);
    }
}
