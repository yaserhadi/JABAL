<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Identity;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Modules\Tenancy\Models\Tenant;
use Modules\Tenancy\Services\TenantDomainProvisioner;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithTenantAddressingProfile;
use Tests\TestCase;

/**
 * BK-108 Gate B — prove Laravel Host {tenant_label} binding when an action accepts ?Tenant $tenant.
 *
 * Permanent regression (not a public diagnostic route): documents that typed Tenant may
 * consume the Host label via implicit model binding / resolveRouteBinding, so Host-only
 * actions must not keep an optional tenant argument.
 */
#[Group('host-profile-contract')]
class HostControllerTenantBindingGateBTest extends TestCase
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

    #[Test]
    public function gate_b_typed_tenant_parameter_may_consume_host_tenant_label(): void
    {
        $tenant = Tenant::factory()->create([
            'slug' => 'gateb-'.Str::lower(Str::random(6)),
            'status' => 'active',
        ]);
        app(TenantDomainProvisioner::class)->ensurePlatformSubdomain($tenant);
        $host = $tenant->slug.'.jabal.test';

        Route::domain('{tenant_label}.jabal.test')
            ->middleware('web')
            ->get('/__bk108_gate_b_typed', function (?Tenant $tenant = null) {
                return response()->json([
                    'action_reached' => true,
                    'param_is_null' => $tenant === null,
                    'param_is_tenant' => $tenant instanceof Tenant,
                    'param_id' => $tenant instanceof Tenant ? (string) $tenant->id : null,
                    'route_has_tenant' => request()->route()?->hasParameter('tenant') ?? false,
                    'route_tenant' => request()->route('tenant'),
                    'route_has_tenant_label' => request()->route()?->hasParameter('tenant_label') ?? false,
                    'route_tenant_label' => request()->route('tenant_label'),
                    'tenancy_initialized' => tenancy()->initialized,
                    'tenancy_id' => tenancy()->tenant?->id ? (string) tenancy()->tenant->id : null,
                ]);
            })
            ->name('bk108.gate-b.typed');

        $response = $this->get('https://'.$host.'/__bk108_gate_b_typed');

        $response->assertOk();
        $payload = $response->json();

        $this->assertTrue($payload['action_reached']);
        $this->assertTrue($payload['route_has_tenant_label']);
        $this->assertSame($tenant->slug, $payload['route_tenant_label']);
        $this->assertFalse($payload['route_has_tenant']);
        $this->assertNull($payload['route_tenant']);

        // Gate B PASS (Host): typed ?Tenant does NOT receive leftover tenant_label
        // (parameter name "tenant" is absent from the Host route). Action is reached;
        // $tenant stays null — no implicit binding exception.
        // Contrast: ?string $tenant DOES receive the leftover label (sibling test).
        // BK-108 still prefers no tenant action argument for Host clarity and to
        // eliminate the string-injection defect class; AuthController dual contract
        // is candidate-only (safe when typed, unsafe when string).
        $this->assertTrue($payload['param_is_null']);
        $this->assertFalse($payload['param_is_tenant']);
        $this->assertNull($payload['param_id']);
    }

    #[Test]
    public function gate_b_string_tenant_parameter_receives_host_tenant_label_positionally(): void
    {
        $tenant = Tenant::factory()->create([
            'slug' => 'gates-'.Str::lower(Str::random(6)),
            'status' => 'active',
        ]);
        app(TenantDomainProvisioner::class)->ensurePlatformSubdomain($tenant);
        $host = $tenant->slug.'.jabal.test';

        Route::domain('{tenant_label}.jabal.test')
            ->middleware('web')
            ->get('/__bk108_gate_b_string', function (?string $tenant = null) {
                return response()->json([
                    'action_reached' => true,
                    'string_param' => $tenant,
                    'route_tenant_label' => request()->route('tenant_label'),
                ]);
            });

        $response = $this->get('https://'.$host.'/__bk108_gate_b_string');
        $response->assertOk();
        $payload = $response->json();

        $this->assertTrue($payload['action_reached']);
        $this->assertSame($tenant->slug, $payload['route_tenant_label']);
        $this->assertSame(
            $tenant->slug,
            $payload['string_param'],
            'Leftover Host tenant_label is injected into mismatched ?string $tenant (BK-108 defect class).'
        );
    }
}
