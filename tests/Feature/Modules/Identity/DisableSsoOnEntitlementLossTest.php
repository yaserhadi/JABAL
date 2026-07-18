<?php

namespace Tests\Feature\Modules\Identity;

use App\Models\User;
use Illuminate\Support\Facades\Config;
use Modules\Billing\Models\Entitlement;
use Modules\Billing\Models\Plan;
use Modules\Billing\Services\SubscriptionService;
use Modules\Identity\Models\Membership;
use Modules\Identity\Models\TenantSsoConfig;
use Modules\Identity\Models\UserSession;
use Modules\Identity\Services\SsoConfigService;
use Modules\Identity\Support\Sso\SsoAuthorizationState;
use Modules\Tenancy\Models\Tenant;
use Modules\Tenancy\Models\TenantDatabaseConfig;
use Modules\Tenancy\Services\TenantRbacProvisioner;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\GrantsSsoEntitlement;
use Tests\TestCase;

class DisableSsoOnEntitlementLossTest extends TestCase
{
    use GrantsSsoEntitlement;

    protected Plan $ssoPlan;

    protected Plan $basicPlan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ssoPlan = Plan::query()->firstOrCreate(
            ['code' => 'sso-test'],
            ['name' => 'SSO Test', 'is_active' => true]
        );
        Entitlement::query()->firstOrCreate(
            ['plan_id' => $this->ssoPlan->id, 'code' => 'sso_available'],
            ['name' => 'SSO Available', 'is_active' => true]
        );

        $this->basicPlan = Plan::query()->firstOrCreate(
            ['code' => 'basic-no-sso'],
            ['name' => 'Basic No SSO', 'is_active' => true]
        );
    }

    #[Test]
    public function plan_change_without_sso_available_disables_sso_config(): void
    {
        [$tenant, $secret] = $this->createTenantWithEnabledSso();

        app(SubscriptionService::class)->changePlan($tenant->id, $this->basicPlan->code);

        tenancy()->initialize($tenant);
        $record = TenantSsoConfig::query()->where('tenant_id', $tenant->id)->first();
        tenancy()->end();

        $this->assertFalse($record->enabled);
        $this->assertTrue($record->disabled_by_entitlement);
        $this->assertSame('https://example.com', $record->issuer_url);
        $this->assertSame('client-id', $record->client_id);
        $this->assertSame($secret, app(SsoConfigService::class)->getDecryptedClientSecret($tenant));
    }

    #[Test]
    public function entitlement_loss_preserves_has_client_secret_semantics(): void
    {
        [$tenant] = $this->createTenantWithEnabledSso();

        app(SubscriptionService::class)->changePlan($tenant->id, $this->basicPlan->code);

        $public = app(SsoConfigService::class)->getForTenant($tenant);
        $this->assertTrue($public['has_client_secret']);
        $this->assertArrayNotHasKey('client_secret', $public);
        $this->assertArrayNotHasKey('client_secret_encrypted', $public);
    }

    #[Test]
    #[Group('path-profile-contract')]
    public function redirect_is_blocked_after_entitlement_loss(): void
    {
        [$tenant] = $this->createTenantWithEnabledSso();

        app(SubscriptionService::class)->changePlan($tenant->id, $this->basicPlan->code);

        $this->get('/t/'.$tenant->id.'/auth/sso/redirect')
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('email');
    }

    #[Test]
    #[Group('path-profile-contract')]
    public function callback_is_blocked_after_entitlement_loss(): void
    {
        [$tenant] = $this->createTenantWithEnabledSso();

        app(SubscriptionService::class)->changePlan($tenant->id, $this->basicPlan->code);

        $state = SsoAuthorizationState::encode(SsoAuthorizationState::mint($tenant->id));

        $this->get('/auth/sso/callback?code=abc&state='.urlencode($state))
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('email');

        $this->assertGuest('web');
    }

    #[Test]
    #[Group('host-profile-contract')]
    public function host_redirect_is_unavailable_after_entitlement_loss(): void
    {
        [$tenant] = $this->createTenantWithEnabledSso();
        app(\Modules\Tenancy\Services\TenantDomainProvisioner::class)->ensurePlatformSubdomain($tenant);

        app(SubscriptionService::class)->changePlan($tenant->id, $this->basicPlan->code);

        $this->call(
            'GET',
            'https://'.$tenant->slug.'.jabal.test/auth/sso/redirect',
            server: [
                'HTTP_HOST' => $tenant->slug.'.jabal.test',
                'SERVER_NAME' => $tenant->slug.'.jabal.test',
            ]
        )->assertNotFound();
    }

    #[Test]
    #[Group('host-profile-contract')]
    public function host_callback_is_unavailable_after_entitlement_loss(): void
    {
        [$tenant] = $this->createTenantWithEnabledSso();

        app(SubscriptionService::class)->changePlan($tenant->id, $this->basicPlan->code);

        $state = SsoAuthorizationState::encode(SsoAuthorizationState::mint($tenant->id));

        $this->call(
            'GET',
            'https://auth.jabal.test/auth/sso/callback?code=abc&state='.urlencode($state),
            server: [
                'HTTP_HOST' => 'auth.jabal.test',
                'SERVER_NAME' => 'auth.jabal.test',
            ]
        )->assertNotFound();

        $this->assertGuest('web');
    }

    #[Test]
    public function existing_sessions_are_not_revoked_on_entitlement_loss(): void
    {
        [$tenant] = $this->createTenantWithEnabledSso();
        $user = $this->createSsoAdmin($tenant);

        tenancy()->initialize($tenant);
        $session = UserSession::query()->create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'session_id' => 'existing-session',
            'last_activity_at' => now(),
            'logged_in_at' => now(),
        ]);
        tenancy()->end();

        app(SubscriptionService::class)->changePlan($tenant->id, $this->basicPlan->code);

        tenancy()->initialize($tenant);
        $this->assertNull($session->fresh()->revoked_at);
        tenancy()->end();
    }

    #[Test]
    public function restoring_entitlement_clears_disable_flag_without_auto_enabling(): void
    {
        [$tenant] = $this->createTenantWithEnabledSso();

        app(SubscriptionService::class)->changePlan($tenant->id, $this->basicPlan->code);
        app(SubscriptionService::class)->changePlan($tenant->id, $this->ssoPlan->code);

        $public = app(SsoConfigService::class)->getForTenant($tenant);
        $this->assertFalse($public['enabled']);
        $this->assertFalse($public['disabled_by_entitlement']);
        $this->assertTrue($public['has_client_secret']);
        $this->assertSame('https://example.com', $public['issuer_url']);
    }

    #[Test]
    public function entitlement_loss_works_with_database_per_tenant_storage(): void
    {
        [$tenant, $secret] = $this->createTenantWithEnabledSso();
        $tenant->update(['isolation_level' => 'database']);
        $sharedTestingDatabase = (string) config('database.connections.tenant.database');

        TenantDatabaseConfig::query()->create([
            'tenant_id' => $tenant->id,
            'isolation_level' => 'database',
            'database_name' => $sharedTestingDatabase,
            'provisioning_status' => 'active',
        ]);

        $connection = 'tenant_db_'.$tenant->id;
        Config::set('database.connections.'.$connection, array_merge(
            config('database.connections.tenant'),
            ['database' => $sharedTestingDatabase]
        ));

        app(SubscriptionService::class)->changePlan($tenant->id, $this->basicPlan->code);

        tenancy()->initialize($tenant);
        $record = TenantSsoConfig::query()->where('tenant_id', $tenant->id)->first();
        tenancy()->end();

        $this->assertTrue($record->disabled_by_entitlement);
        $this->assertFalse($record->enabled);
        $this->assertSame($secret, app(SsoConfigService::class)->getDecryptedClientSecret($tenant));
    }

    /**
     * @return array{0: Tenant, 1: string}
     */
    protected function createTenantWithEnabledSso(): array
    {
        $tenant = Tenant::factory()->create([
            'status' => 'active',
        ]);
        $this->grantSsoAvailable($tenant);

        $secret = 'sso-secret-'.uniqid();
        tenancy()->initialize($tenant);
        app(SsoConfigService::class)->update($tenant, [
            'enabled' => true,
            'issuer_url' => 'https://example.com',
            'client_id' => 'client-id',
            'client_secret' => $secret,
            'scopes' => ['openid', 'profile', 'email'],
        ]);
        tenancy()->end();

        return [$tenant, $secret];
    }

    protected function createSsoAdmin(Tenant $tenant): User
    {
        tenancy()->initialize($tenant);
        $user = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'SSO Admin',
            'email' => 'sso-admin-'.uniqid().'@example.com',
            'password' => 'password',
        ]);
        Membership::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'membership_type' => 'owner',
            'status' => 'active',
            'joined_at' => now(),
        ]);
        tenancy()->end();

        $provisioner = app(TenantRbacProvisioner::class);
        $provisioner->ensureGlobalPermissions();
        $provisioner->ensureRolesForTenant($tenant);
        $provisioner->assignTenantAdminRole($user, $tenant);

        return $user;
    }
}
