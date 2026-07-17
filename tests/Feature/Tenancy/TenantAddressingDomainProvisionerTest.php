<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Http\Auth\TenantEntryUrlResolver;
use App\Models\Domain;
use App\Support\Tenancy\TenantAddressingProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Tenancy\Exceptions\DomainCollisionException;
use Modules\Tenancy\Exceptions\TenantHardDeleteProhibitedException;
use Modules\Tenancy\Models\Tenant;
use Modules\Tenancy\Services\TenantDomainProvisioner;
use Modules\Tenancy\Support\TenantHandleValidator;
use Tests\TestCase;

class TenantAddressingDomainProvisionerTest extends TestCase
{
    use RefreshDatabase;

    public function test_ensure_platform_subdomain_creates_row_with_locked_category(): void
    {
        $tenant = Tenant::factory()->create(['slug' => 'acme-co']);

        $domain = app(TenantDomainProvisioner::class)->ensurePlatformSubdomain($tenant);

        $this->assertSame('acme-co', $domain->domain);
        $this->assertSame((string) $tenant->id, (string) $domain->tenant_id);
        $this->assertSame('platform_subdomain', $domain->data['category'] ?? null);
        $this->assertInstanceOf(Domain::class, $domain);
    }

    public function test_same_tenant_reprovision_is_idempotent(): void
    {
        $tenant = Tenant::factory()->create(['slug' => 'idempotent']);
        $provisioner = app(TenantDomainProvisioner::class);

        $first = $provisioner->ensurePlatformSubdomain($tenant);
        $second = $provisioner->ensurePlatformSubdomain($tenant);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, Domain::query()->where('domain', 'idempotent')->count());
    }

    public function test_other_tenant_collision_hard_fails(): void
    {
        $a = Tenant::factory()->create(['slug' => 'taken-label']);
        $b = Tenant::factory()->create(['slug' => 'taken-label-b']);
        // Force B to attempt A's label
        $b->slug = 'taken-label';

        app(TenantDomainProvisioner::class)->ensurePlatformSubdomain($a);

        $this->expectException(DomainCollisionException::class);
        app(TenantDomainProvisioner::class)->ensurePlatformSubdomain($b);
    }

    public function test_force_delete_is_prohibited(): void
    {
        $tenant = Tenant::factory()->create(['slug' => 'no-hard-delete']);
        app(TenantDomainProvisioner::class)->ensurePlatformSubdomain($tenant);

        $this->expectException(TenantHardDeleteProhibitedException::class);
        $tenant->forceDelete();
    }

    public function test_entry_url_is_absolute_in_path_profile(): void
    {
        config([
            'tenancy_addressing.profile' => 'path',
            'tenancy_addressing.platform_host' => 'localhost',
            'tenancy_addressing.canonical_scheme' => 'http',
            'tenancy_addressing.canonical_port' => null,
        ]);
        $tenant = Tenant::factory()->create(['slug' => 'path-entry']);

        $url = app(TenantEntryUrlResolver::class)->entryUrl($tenant);

        $this->assertSame('http://localhost/t/path-entry', $url);
    }

    public function test_entry_url_is_absolute_host_canonical_from_config_not_request(): void
    {
        config([
            'tenancy_addressing.profile' => 'host',
            'tenancy_addressing.platform_base_domain' => 'jabal.test',
            'tenancy_addressing.canonical_scheme' => 'https',
            'tenancy_addressing.canonical_port' => null,
        ]);

        $tenant = Tenant::factory()->create(['slug' => 'host-entry']);
        $url = app(TenantEntryUrlResolver::class)->entryUrl($tenant);

        $this->assertSame('https://host-entry.jabal.test', $url);
    }

    public function test_handle_validator_normalizes_lowercase(): void
    {
        $normalized = app(TenantHandleValidator::class)->normalize('AcMe');
        $this->assertSame('acme', $normalized);
    }

    public function test_addressing_profile_rejects_host_redirect(): void
    {
        config(['tenancy_addressing.profile' => 'host_redirect']);

        $this->expectException(\InvalidArgumentException::class);
        app(TenantAddressingProfile::class)->assertValidConfiguration();
    }
}
