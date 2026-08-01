<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use Illuminate\Routing\Exceptions\UrlGenerationException;
use Illuminate\Support\Facades\URL;
use Modules\Tenancy\Models\Tenant;
use Modules\Tenancy\Services\TenantDomainProvisioner;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithTenantAddressingProfile;
use Tests\TestCase;

/**
 * BK-109 — signed URL parameter-before-sign contract (no product signed generators yet).
 */
class HostTenantSignedUrlParameterContractTest extends TestCase
{
    use InteractsWithTenantAddressingProfile;

    protected function setUp(): void
    {
        $this->forceAddressingEnv('host');
        parent::setUp();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->restoreAddressingEnv();
    }

    #[Test]
    #[Group('host-profile-contract')]
    public function temporary_signed_route_requires_tenant_label_before_signing(): void
    {
        $this->expectException(UrlGenerationException::class);
        URL::temporarySignedRoute('identity.sso.enrollments.index', now()->addMinutes(5), []);
    }

    #[Test]
    #[Group('host-profile-contract')]
    public function temporary_signed_route_validates_on_intended_host_and_rejects_post_sign_param_swap(): void
    {
        $tenant = Tenant::factory()->create([
            'slug' => 'sig-'.substr(md5((string) microtime(true)), 0, 8),
            'status' => 'active',
        ]);
        app(TenantDomainProvisioner::class)->ensurePlatformSubdomain($tenant);
        $host = $tenant->slug.'.jabal.test';

        $signed = URL::temporarySignedRoute(
            'identity.sso.enrollments.index',
            now()->addMinutes(5),
            ['tenant_label' => $tenant->slug],
            absolute: true
        );

        $this->assertStringContainsString($host, $signed);
        $this->assertTrue(URL::hasValidSignature(request()->create($signed, 'GET')));

        // Post-sign Host label substitution must invalidate the signature.
        $tampered = str_replace($tenant->slug.'.jabal.test', 'other-tenant.jabal.test', $signed);
        $this->assertNotSame($signed, $tampered);
        $this->assertFalse(URL::hasValidSignature(request()->create($tampered, 'GET')));
    }
}
