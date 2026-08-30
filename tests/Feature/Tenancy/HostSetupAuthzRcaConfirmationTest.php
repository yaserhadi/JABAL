<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Http\Middleware\EnsureUserBelongsToTenant;
use App\Models\Rbac\TenantRole as Role;
use Illuminate\Support\Facades\Route;
use Modules\Tenancy\Services\TenantDomainProvisioner;
use Modules\Tenancy\Services\TenantRbacProvisioner;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\CapturePermissionsTeamContext;
use Tests\Support\InteractsWithTenantAddressingProfile;
use Tests\TestCase;

/**
 * BK-115 Host RCA confirmation only — no product fix under this authorization.
 *
 * Proves Spatie team / auth / tenant context immediately before permission evaluation.
 */
#[Group('host-profile-contract')]
class HostSetupAuthzRcaConfirmationTest extends TestCase
{
    use InteractsWithTenantAddressingProfile;

    protected function setUp(): void
    {
        $this->forceAddressingEnv('host');
        parent::setUp();
        CapturePermissionsTeamContext::$snapshot = null;
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->restoreAddressingEnv();
        CapturePermissionsTeamContext::$snapshot = null;
    }

    #[Test]
    public function captures_context_immediately_before_permission_middleware_on_setup_shaped_stack(): void
    {
        $user = $this->registerTenantUser('Host RCA Admin', 'host-rca-'.uniqid().'@example.com');
        $tenant = $user->personalTenant();
        $tenant->forceFill(['status' => 'active'])->save();
        app(TenantDomainProvisioner::class)->ensurePlatformSubdomain($tenant);

        $host = $tenant->slug.'.jabal.test';
        $expectedTeam = $tenant->getTenantKey();

        Route::domain('{tenant_label}.jabal.test')
            ->middleware([
                'web',
                'auth',
                EnsureUserBelongsToTenant::class,
                CapturePermissionsTeamContext::class,
                'permission:tenant.setup.view',
            ])
            ->get('/__bk115_host_rca_setup', function () {
                return response()->json([
                    'ok' => true,
                    'snapshot' => CapturePermissionsTeamContext::$snapshot,
                ]);
            })
            ->name('bk115.host-rca.setup');

        $response = $this->actingAs($user)->get('https://'.$host.'/__bk115_host_rca_setup');

        $this->assertNotNull(CapturePermissionsTeamContext::$snapshot, 'Probe middleware must run before permission evaluation');
        $snap = CapturePermissionsTeamContext::$snapshot;

        $this->assertSame((string) $tenant->id, (string) $snap['tenant_id']);
        $this->assertSame((string) $user->id, (string) $snap['authenticated_user_id']);
        $this->assertSame((string) $expectedTeam, (string) $snap['permissions_team_id']);
        $this->assertSame((string) $expectedTeam, (string) $snap['expected_team_id']);
        $this->assertSame($tenant->slug, $snap['route_tenant_label']);
        $this->assertTrue($snap['can_setup_view_at_probe'], 'Admin must can(setup.view) with team set at probe');

        // Controlled stack with team restored by EnsureUserBelongsToTenant:
        // 200 ⇒ NOT CONFIRMED defect on this stack (R1 lab 403 needs other cause if still present).
        // 403 with can=true at probe ⇒ CONFIRMED PRODUCT DEFECT in PermissionMiddleware path.
        $response->assertOk();
    }

    #[Test]
    public function user_without_setup_permission_remains_denied(): void
    {
        $user = $this->registerTenantUser('Host RCA Member', 'host-rca-m-'.uniqid().'@example.com');
        $tenant = $user->personalTenant();
        $tenant->forceFill(['status' => 'active'])->save();
        app(TenantDomainProvisioner::class)->ensurePlatformSubdomain($tenant);

        // Registration assigns Tenant Admin — strip to a role without setup.view.
        $provisioner = app(TenantRbacProvisioner::class);
        $provisioner->ensureGlobalPermissions();
        $provisioner->ensureRolesForTenant($tenant);

        app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->getTenantKey());
        $user->syncRoles([]);
        $guard = config('auth.defaults.guard');
        $member = Role::query()->firstOrCreate(
            ['name' => 'member', 'guard_name' => $guard, 'tenant_id' => $tenant->id],
            ['name' => 'member', 'guard_name' => $guard, 'tenant_id' => $tenant->id]
        );
        $user->assignRole($member);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);

        $host = $tenant->slug.'.jabal.test';

        Route::domain('{tenant_label}.jabal.test')
            ->middleware([
                'web',
                'auth',
                EnsureUserBelongsToTenant::class,
                'permission:tenant.setup.view',
            ])
            ->get('/__bk115_host_rca_denied', fn () => response()->json(['ok' => true]))
            ->name('bk115.host-rca.denied');

        $response = $this->actingAs($user)->get('https://'.$host.'/__bk115_host_rca_denied');
        $response->assertForbidden();
    }

    #[Test]
    public function production_setup_route_probe_records_pre_controller_permission_outcome(): void
    {
        $user = $this->registerTenantUser('Host RCA Setup', 'host-rca-s-'.uniqid().'@example.com');
        $tenant = $user->personalTenant();
        $tenant->forceFill(['status' => 'active'])->save();
        app(TenantDomainProvisioner::class)->ensurePlatformSubdomain($tenant);

        $host = $tenant->slug.'.jabal.test';
        $url = 'https://'.$host.'/setup';

        Route::domain('{tenant_label}.jabal.test')
            ->middleware(['web', 'auth', EnsureUserBelongsToTenant::class])
            ->get('/__bk115_host_rca_after_belong', function (\Illuminate\Http\Request $request) {
                $registrar = app(PermissionRegistrar::class);
                $tenant = tenancy()->tenant;

                return response()->json([
                    'tenant_id' => $tenant?->id,
                    'permissions_team_id' => $registrar->getPermissionsTeamId(),
                    'expected_team_id' => $tenant?->getTenantKey(),
                    'user_id' => $request->user()?->id,
                    'can_setup_view' => $request->user()?->can('tenant.setup.view'),
                ]);
            });

        $probe = $this->actingAs($user)->get('https://'.$host.'/__bk115_host_rca_after_belong');
        $probe->assertOk();
        $body = $probe->json();
        $this->assertSame((string) $tenant->id, (string) $body['tenant_id']);
        $this->assertSame((string) $body['expected_team_id'], (string) $body['permissions_team_id']);
        $this->assertTrue($body['can_setup_view']);

        $setup = $this->actingAs($user)->get($url);
        $status = $setup->status();

        if (in_array($status, [200, 302, 303, 307, 308], true)) {
            $this->assertTrue(
                true,
                'HOST RCA CLASSIFICATION: NOT CONFIRMED PRODUCT DEFECT on this host test stack — /setup status='.$status
                .' with post-belong team='.$body['permissions_team_id'].' can=true'
            );

            return;
        }

        if ($status === 403) {
            $this->fail(
                'HOST RCA CLASSIFICATION: CONFIRMED PRODUCT DEFECT CANDIDATE — /setup 403 while post-belong '
                .'can(setup.view)=true team='.$body['permissions_team_id']
                .' — STOP for Owner minimal-fix review (no Host fix under this GO)'
            );
        }

        $this->fail('HOST RCA CLASSIFICATION: NOT CONFIRMED / OTHER CAUSE — unexpected /setup status '.$status);
    }
}
