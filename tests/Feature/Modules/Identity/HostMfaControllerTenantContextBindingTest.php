<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Identity;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\Billing\Models\Entitlement;
use Modules\Billing\Models\Plan;
use Modules\Billing\Models\Subscription;
use Modules\Identity\Models\Membership;
use Modules\Tenancy\Models\Tenant;
use Modules\Tenancy\Services\TenantDomainProvisioner;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithTenantAddressingProfile;
use Tests\TestCase;

/**
 * BK-108 — Host MFA controller tenant-context binding (multi-signal).
 */
#[Group('host-profile-contract')]
class HostMfaControllerTenantContextBindingTest extends TestCase
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

    /**
     * @return array{tenant: Tenant, user: User, host: string}
     */
    protected function prepareHostTenantUser(bool $withMfaEntitlement = true): array
    {
        $tenant = Tenant::factory()->create([
            'slug' => 'mfa-'.Str::lower(Str::random(8)),
            'status' => 'active',
        ]);
        if ($withMfaEntitlement) {
            $this->grantMfaAvailable($tenant);
        }
        app(TenantDomainProvisioner::class)->ensurePlatformSubdomain($tenant);

        tenancy()->initialize($tenant);
        $user = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'MFA Host User',
            'email' => 'mfa-host-'.uniqid().'@example.com',
            'password' => 'password',
        ]);
        Membership::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'membership_type' => 'member',
            'status' => 'active',
            'joined_at' => now(),
        ]);
        tenancy()->end();

        return [
            'tenant' => $tenant,
            'user' => $user,
            'host' => $tenant->slug.'.jabal.test',
        ];
    }

    protected function grantMfaAvailable(Tenant $tenant): void
    {
        $plan = Plan::query()->firstOrCreate(
            ['code' => 'mfa-host-test'],
            ['name' => 'MFA Host Test', 'is_active' => true]
        );

        Entitlement::query()->firstOrCreate(
            ['plan_id' => $plan->id, 'code' => 'mfa_available'],
            ['name' => 'MFA Available', 'is_active' => true]
        );

        Subscription::query()->updateOrCreate(
            ['tenant_id' => $tenant->id, 'status' => 'active'],
            [
                'id' => Str::uuid()->toString(),
                'plan_id' => $plan->id,
                'starts_at' => now(),
            ]
        );
    }

    #[Test]
    public function enroll_required_uses_initialized_tenant_uuid_not_host_label(): void
    {
        $fixture = $this->prepareHostTenantUser();
        $this->actingAsTenantUser($fixture['user'], $fixture['tenant']);

        $response = $this->get('https://'.$fixture['host'].'/security/mfa/enroll');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Security/MfaEnroll')
            ->where('tenant.id', (string) $fixture['tenant']->id)
            ->where('tenant.slug', $fixture['tenant']->slug)
        );

        $this->assertSame($fixture['tenant']->slug, request()->route('tenant_label'));
        $this->assertFalse(request()->route()?->hasParameter('tenant') ?? false);
        $this->assertTrue(tenancy()->initialized);
        $this->assertSame((string) $fixture['tenant']->id, (string) tenancy()->tenant->id);

        $mfaSource = file_get_contents(base_path('Modules/Identity/app/Http/Controllers/MfaController.php'));
        $this->assertStringContainsString('resolveInitializedTenant', $mfaSource);
        $this->assertStringNotContainsString('findOrFail($tenant)', $mfaSource);
        $this->assertStringNotContainsString('?string $tenant', $mfaSource);
    }

    #[Test]
    public function enroll_without_initialized_tenancy_fail_closed(): void
    {
        // Hit Tenant Host path without a provisioned domain → Host init cannot resolve tenant.
        $response = $this->get('https://unknown-label.jabal.test/security/mfa/enroll');
        $this->assertTrue(
            in_array($response->status(), [404, 403], true),
            'Expected fail-closed status, got '.$response->status()
        );
    }

    #[Test]
    public function challenge_required_references_initialized_tenant(): void
    {
        $fixture = $this->prepareHostTenantUser();
        $this->actingAsTenantUser($fixture['user'], $fixture['tenant']);

        $response = $this->get('https://'.$fixture['host'].'/security/mfa/challenge');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Security/MfaChallenge')
            ->where('tenant.id', (string) $fixture['tenant']->id)
        );
    }

    #[Test]
    public function unauthenticated_enroll_redirects_to_login(): void
    {
        $fixture = $this->prepareHostTenantUser();

        $response = $this->get('https://'.$fixture['host'].'/security/mfa/enroll');

        $response->assertRedirect();
        $location = (string) $response->headers->get('Location');
        $this->assertStringContainsString('/login', $location);
    }

    #[Test]
    public function platform_host_cannot_invoke_tenant_mfa_enroll(): void
    {
        $fixture = $this->prepareHostTenantUser();
        $this->actingAsTenantUser($fixture['user'], $fixture['tenant']);

        $response = $this->get('https://platform.jabal.test/security/mfa/enroll');
        $this->assertTrue(
            in_array($response->status(), [404, 403], true),
            'Platform Host must not serve Tenant MFA enroll; got '.$response->status()
        );
    }

    #[Test]
    public function auth_host_cannot_invoke_tenant_mfa_enroll(): void
    {
        $fixture = $this->prepareHostTenantUser();
        $this->actingAsTenantUser($fixture['user'], $fixture['tenant']);

        $response = $this->get('https://auth.jabal.test/security/mfa/enroll');
        $this->assertTrue(
            in_array($response->status(), [404, 403], true),
            'Auth Host must not serve Tenant MFA enroll; got '.$response->status()
        );
    }

    #[Test]
    public function host_label_of_other_tenant_does_not_override_initialized_context_when_domain_matches_init(): void
    {
        $fixture = $this->prepareHostTenantUser();
        $other = Tenant::factory()->create([
            'slug' => 'other-'.Str::lower(Str::random(6)),
            'status' => 'active',
        ]);
        app(TenantDomainProvisioner::class)->ensurePlatformSubdomain($other);

        $this->actingAsTenantUser($fixture['user'], $fixture['tenant']);

        // Canonical Host for fixture tenant — Stancl initializes fixture tenant from domain.
        $response = $this->get('https://'.$fixture['host'].'/security/mfa/enroll');
        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('tenant.id', (string) $fixture['tenant']->id)
            ->where('tenant.id', fn ($id) => $id !== (string) $other->id)
        );
    }

    #[Test]
    public function revoke_session_route_binds_session_without_tenant_action_argument(): void
    {
        $route = \Illuminate\Support\Facades\Route::getRoutes()->getByName('identity.security-settings.revoke-session');
        $this->assertNotNull($route);
        $this->assertContains('session', $route->parameterNames());
        $this->assertNotContains('tenant', $route->parameterNames());

        $ref = new \ReflectionMethod(
            \Modules\Identity\Http\Controllers\SecuritySettingsController::class,
            'revokeSession'
        );
        $params = array_map(static fn (\ReflectionParameter $p) => $p->getName(), $ref->getParameters());
        $this->assertSame(['request', 'session'], $params);
    }
}
