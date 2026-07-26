<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Http\Auth\TenantEntryUrlResolver;
use App\Http\Middleware\RequestHostClassifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Modules\Tenancy\Models\Tenant;
use Modules\Tenancy\Services\TenantDomainProvisioner;
use Tests\Support\InteractsWithTenantAddressingProfile;
use Tests\TestCase;

class TenantAddressingSecurityTest extends TestCase
{
    use InteractsWithTenantAddressingProfile;
    use RefreshDatabase;

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

    public function test_trust_hosts_regex_accepts_single_label_only(): void
    {
        $classifier = app(RequestHostClassifier::class);

        $this->assertSame(
            RequestHostClassifier::CLASS_TENANT_CANDIDATE,
            $classifier->classify(Request::create('https://acme.jabal.test/'))
        );
        $this->assertSame(
            RequestHostClassifier::CLASS_UNKNOWN,
            $classifier->classify(Request::create('https://a.b.jabal.test/'))
        );
        $this->assertSame(
            RequestHostClassifier::CLASS_UNKNOWN,
            $classifier->classify(Request::create('https://jabal.test.attacker.com/'))
        );
        $this->assertSame(
            RequestHostClassifier::CLASS_UNKNOWN,
            $classifier->classify(Request::create('https://evil-jabal.test/'))
        );
    }

    public function test_spoofed_host_does_not_enter_generated_urls(): void
    {
        $tenant = Tenant::factory()->create(['slug' => 'canon', 'status' => 'active']);
        app(TenantDomainProvisioner::class)->ensurePlatformSubdomain($tenant);

        $url = app(TenantEntryUrlResolver::class)->entryUrl($tenant);

        $this->assertSame('https://canon.jabal.test', $url);
        $this->assertStringNotContainsString('evil', $url);
    }

    public function test_forwarded_headers_ignored_without_trusted_proxies(): void
    {
        config([
            'tenancy_addressing.trust_forwarded_headers' => false,
            'tenancy_addressing.trusted_proxies' => [],
        ]);
        \App\Providers\AppServiceProvider::configureTrustedProxiesFromConfig();

        $tenant = Tenant::factory()->create(['slug' => 'fwd', 'status' => 'active']);
        app(TenantDomainProvisioner::class)->ensurePlatformSubdomain($tenant);

        // X-Forwarded-Host must not make evil host resolve as the Tenant Host.
        $this->call(
            'GET',
            'http://fwd.jabal.test/login',
            server: [
                'HTTP_HOST' => 'fwd.jabal.test',
                'SERVER_NAME' => 'fwd.jabal.test',
                'HTTP_X_FORWARDED_HOST' => 'evil.example.com',
            ]
        )->assertOk();
    }

    public function test_boot_fails_when_forwarded_mode_lacks_trusted_list(): void
    {
        config([
            'tenancy_addressing.trust_forwarded_headers' => true,
            'tenancy_addressing.trusted_proxies' => [],
        ]);

        $this->expectException(\RuntimeException::class);
        app(\App\Support\Tenancy\TenantAddressingProfile::class)->assertValidConfiguration();
    }

    public function test_session_tenant_mismatch_fails_closed(): void
    {
        $tenantA = Tenant::factory()->create(['slug' => 'ctx-a', 'status' => 'active']);
        $tenantB = Tenant::factory()->create(['slug' => 'ctx-b', 'status' => 'active']);
        app(TenantDomainProvisioner::class)->ensurePlatformSubdomain($tenantA);

        if (tenancy()->initialized) {
            tenancy()->end();
        }
        tenancy()->initialize($tenantA);

        $request = Request::create('https://ctx-a.jabal.test/login', 'GET');
        $request->headers->set('HOST', 'ctx-a.jabal.test');

        $session = app('session.store');
        $session->start();
        $session->put('tenant_id', (string) $tenantB->id);
        $request->setLaravelSession($session);

        try {
            app(\App\Http\Middleware\RejectTenancyContextConflict::class)->handle(
                $request,
                static fn () => response('ok')
            );
            $this->fail('Expected tenant context conflict to abort.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        } finally {
            if (tenancy()->initialized) {
                tenancy()->end();
            }
        }
    }

    public function test_x_tenant_id_header_mismatch_fails_closed(): void
    {
        $tenantA = Tenant::factory()->create(['slug' => 'hdr-a', 'status' => 'active']);
        $tenantB = Tenant::factory()->create(['slug' => 'hdr-b', 'status' => 'active']);
        app(TenantDomainProvisioner::class)->ensurePlatformSubdomain($tenantA);
        app(TenantDomainProvisioner::class)->ensurePlatformSubdomain($tenantB);

        $this->withServerVariables([
            'HTTP_HOST' => 'hdr-a.jabal.test',
            'SERVER_NAME' => 'hdr-a.jabal.test',
        ])->withHeaders(['X-Tenant-Id' => (string) $tenantB->id])
            ->get('http://hdr-a.jabal.test/login')
            ->assertForbidden();
    }
}
