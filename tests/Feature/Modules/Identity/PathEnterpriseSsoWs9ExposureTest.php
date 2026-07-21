<?php

namespace Tests\Feature\Modules\Identity;

use Illuminate\Support\Str;
use Modules\Identity\Services\SsoConfigService;
use Modules\Identity\Services\SsoOperationalExposureService;
use Modules\Tenancy\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\GrantsSsoEntitlement;
use Tests\Support\InteractsWithTenantAddressingProfile;
use Tests\TestCase;

/** BK-082 WS9 — Path must not expose Host Enterprise SSO start. */
class PathEnterpriseSsoWs9ExposureTest extends TestCase
{
    use GrantsSsoEntitlement;
    use InteractsWithTenantAddressingProfile;

    protected function setUp(): void
    {
        $this->forceAddressingEnv('path');
        parent::setUp();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->restoreAddressingEnv();
    }

    #[Test]
    public function path_uses_legacy_sso_redirect_not_enterprise_start(): void
    {
        $tenant = Tenant::factory()->create([
            'status' => 'active',
            'slug' => 'ws9p-'.Str::lower(Str::random(6)),
        ]);
        $this->grantSsoAvailable($tenant);
        tenancy()->initialize($tenant);
        app(SsoConfigService::class)->update($tenant, [
            'enabled' => true,
            'issuer_url' => 'https://idp.example.com',
            'client_id' => 'client-id',
            'client_secret' => 'secret',
        ]);
        tenancy()->end();

        $exposure = app(SsoOperationalExposureService::class);
        $this->assertTrue($exposure->isExposedOnTenantLogin($tenant));
        $url = $exposure->startUrlForTenantLogin($tenant);
        $this->assertStringContainsString('sso', strtolower($url));
        $this->assertStringNotContainsString('enterprise-sso/start', $url);
        $this->get('/auth/enterprise-sso/start')->assertNotFound();
    }
}
