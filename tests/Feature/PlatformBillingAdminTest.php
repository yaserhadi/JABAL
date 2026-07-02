<?php

namespace Tests\Feature;

use App\Models\PlatformUser;
use App\Support\Contracts\Billing\TenantEntitlementsResolver;
use App\Support\Contracts\Billing\TenantSeatLimitResolver;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Modules\Billing\Events\SubscriptionCancelled;
use Modules\Billing\Events\SubscriptionCreated;
use Modules\Billing\Events\SubscriptionPlanChanged;
use Modules\Billing\Events\SubscriptionReactivated;
use Modules\Billing\Events\SubscriptionSuspended;
use Modules\Billing\Exceptions\InvalidSubscriptionTransitionException;
use Modules\Billing\Models\Entitlement;
use Modules\Billing\Models\Plan;
use Modules\Billing\Models\Subscription;
use Modules\Billing\Services\SubscriptionService;
use Modules\Tenancy\Models\Tenant;
use Tests\TestCase;

class PlatformBillingAdminTest extends TestCase
{
    protected function seedBillingCatalog(): Plan
    {
        $this->seed(\Database\Seeders\BillingCatalogSeeder::class);

        return Plan::query()->where('code', Plan::DEFAULT_CODE)->firstOrFail();
    }

    protected function createEnterprisePlan(): Plan
    {
        $plan = Plan::query()->firstOrCreate(
            ['code' => 'enterprise'],
            [
                'name' => 'Enterprise',
                'description' => 'Enterprise plan',
                'is_active' => true,
                'seat_limit' => 100,
            ]
        );

        foreach (['mfa_available', 'mfa_required'] as $code) {
            Entitlement::query()->firstOrCreate(
                ['plan_id' => $plan->id, 'code' => $code],
                ['name' => $code, 'is_active' => true]
            );
        }

        return $plan;
    }

    protected function platformOperatorWithBilling(): PlatformUser
    {
        $user = PlatformUser::create([
            'name' => 'Billing Op',
            'email' => 'billing-op-'.uniqid().'@platform.test',
            'password' => Hash::make('password'),
            'is_active' => true,
        ]);
        $this->grantPlatformAccess($user);

        return $user;
    }

    protected function platformOperatorAccessOnly(): PlatformUser
    {
        $user = PlatformUser::create([
            'name' => 'Access Only',
            'email' => 'access-only-'.uniqid().'@platform.test',
            'password' => Hash::make('password'),
            'is_active' => true,
        ]);

        $accessPerm = \App\Models\PlatformPermission::firstOrCreate(
            ['name' => 'platform.access', 'guard_name' => 'platform']
        );

        \Illuminate\Support\Facades\DB::connection('central')->table('platform_model_has_permissions')->insertOrIgnore([
            'platform_permission_id' => $accessPerm->id,
            'model_type' => PlatformUser::class,
            'model_id' => $user->id,
        ]);

        return $user;
    }

    public function test_billing_catalog_seeder_never_creates_subscriptions(): void
    {
        $tenant = Tenant::factory()->create();
        $this->assertSame(0, Subscription::query()->where('tenant_id', $tenant->id)->count());

        $this->seed(\Database\Seeders\BillingCatalogSeeder::class);

        $this->assertSame(0, Subscription::query()->where('tenant_id', $tenant->id)->count());
        $this->assertTrue(Plan::query()->where('code', Plan::DEFAULT_CODE)->exists());
    }

    public function test_unauthenticated_billing_routes_redirect_to_login(): void
    {
        $tenant = Tenant::factory()->create();

        $this->get('/platform/billing/plans')->assertRedirect(route('platform.login'));
        $this->get('/platform/billing/tenants/'.$tenant->id.'/subscription')
            ->assertRedirect(route('platform.login'));
    }

    public function test_operator_without_billing_manage_gets_403_on_mutations(): void
    {
        $this->seedBillingCatalog();
        $tenant = Tenant::factory()->create();
        app(SubscriptionService::class)->ensureDefaultSubscription($tenant->id);

        $user = $this->platformOperatorAccessOnly();

        $this->actingAs($user, 'platform')
            ->patchJson('/platform/billing/tenants/'.$tenant->id.'/subscription/plan', [
                'plan_code' => Plan::DEFAULT_CODE,
            ])
            ->assertForbidden();
    }

    public function test_list_plans_returns_catalog(): void
    {
        $this->seedBillingCatalog();
        $user = $this->platformOperatorWithBilling();

        $response = $this->actingAs($user, 'platform')
            ->getJson('/platform/billing/plans');

        $response->assertOk();
        $response->assertJsonPath('data.plans.0.code', Plan::DEFAULT_CODE);
        $response->assertJsonFragment(['code' => 'mfa_available']);
    }

    public function test_change_plan_dispatches_event(): void
    {
        Event::fake([SubscriptionPlanChanged::class]);

        $standard = $this->seedBillingCatalog();
        $enterprise = $this->createEnterprisePlan();
        $tenant = Tenant::factory()->create();
        app(SubscriptionService::class)->ensureDefaultSubscription($tenant->id, $standard->code);

        $user = $this->platformOperatorWithBilling();

        $response = $this->actingAs($user, 'platform')
            ->patchJson('/platform/billing/tenants/'.$tenant->id.'/subscription/plan', [
                'plan_code' => $enterprise->code,
            ]);

        $response->assertOk();
        $response->assertJsonPath('data.plan_code', 'enterprise');

        Event::assertDispatched(SubscriptionPlanChanged::class, function (SubscriptionPlanChanged $event) use ($tenant) {
            return $event->tenantId === $tenant->id
                && $event->previousPlanCode === Plan::DEFAULT_CODE
                && $event->newPlanCode === 'enterprise';
        });
    }

    public function test_suspend_and_reactivate_lifecycle(): void
    {
        Event::fake([SubscriptionSuspended::class, SubscriptionReactivated::class]);

        $this->seedBillingCatalog();
        $tenant = Tenant::factory()->create();
        app(SubscriptionService::class)->ensureDefaultSubscription($tenant->id);
        $user = $this->platformOperatorWithBilling();

        $this->actingAs($user, 'platform')
            ->postJson('/platform/billing/tenants/'.$tenant->id.'/subscription/suspend')
            ->assertOk()
            ->assertJsonPath('data.status', Subscription::STATUS_SUSPENDED);

        Event::assertDispatched(SubscriptionSuspended::class);

        $resolver = app(TenantEntitlementsResolver::class);
        $this->assertSame([], $resolver->entitlementsForTenant($tenant->id));

        $this->actingAs($user, 'platform')
            ->postJson('/platform/billing/tenants/'.$tenant->id.'/subscription/reactivate')
            ->assertOk()
            ->assertJsonPath('data.status', Subscription::STATUS_ACTIVE);

        Event::assertDispatched(SubscriptionReactivated::class);
        $this->assertContains('mfa_available', $resolver->entitlementsForTenant($tenant->id));
    }

    public function test_cancel_is_terminal_and_clears_entitlements(): void
    {
        Event::fake([SubscriptionCancelled::class]);

        $this->seedBillingCatalog();
        $tenant = Tenant::factory()->create();
        app(SubscriptionService::class)->ensureDefaultSubscription($tenant->id);
        $user = $this->platformOperatorWithBilling();

        $this->actingAs($user, 'platform')
            ->postJson('/platform/billing/tenants/'.$tenant->id.'/subscription/cancel')
            ->assertOk()
            ->assertJsonPath('data.status', Subscription::STATUS_CANCELED);

        Event::assertDispatched(SubscriptionCancelled::class);

        $resolver = app(TenantEntitlementsResolver::class);
        $this->assertSame([], $resolver->entitlementsForTenant($tenant->id));
    }

    public function test_reactivate_from_canceled_is_rejected(): void
    {
        $this->seedBillingCatalog();
        $tenant = Tenant::factory()->create();
        $service = app(SubscriptionService::class);
        $service->ensureDefaultSubscription($tenant->id);
        $service->cancel($tenant->id);

        $this->expectException(InvalidSubscriptionTransitionException::class);
        $service->reactivate($tenant->id);
    }

    public function test_seat_limit_override_does_not_mutate_plan(): void
    {
        $plan = $this->seedBillingCatalog();
        $tenant = Tenant::factory()->create();
        app(SubscriptionService::class)->ensureDefaultSubscription($tenant->id);
        $user = $this->platformOperatorWithBilling();

        $this->actingAs($user, 'platform')
            ->patchJson('/platform/billing/tenants/'.$tenant->id.'/subscription/seat-limit', [
                'seat_limit' => 42,
            ])
            ->assertOk()
            ->assertJsonPath('data.seat_limit', 42);

        $plan->refresh();
        $this->assertNull($plan->seat_limit);

        $seatResolver = app(TenantSeatLimitResolver::class);
        $this->assertSame(42, $seatResolver->seatLimitForTenant($tenant->id));

        $this->actingAs($user, 'platform')
            ->patchJson('/platform/billing/tenants/'.$tenant->id.'/subscription/seat-limit', [
                'seat_limit' => null,
            ])
            ->assertOk()
            ->assertJsonPath('data.seat_limit', null);
    }

    public function test_bootstrap_command_is_idempotent(): void
    {
        $this->seedBillingCatalog();
        $tenant = Tenant::factory()->create();

        $this->artisan('billing:bootstrap-subscriptions')->assertSuccessful();
        $this->assertSame(1, Subscription::query()->where('tenant_id', $tenant->id)->count());

        $this->artisan('billing:bootstrap-subscriptions')->assertSuccessful();
        $this->assertSame(1, Subscription::query()->where('tenant_id', $tenant->id)->count());
    }

    public function test_ensure_default_subscription_dispatches_created_event(): void
    {
        Event::fake([SubscriptionCreated::class]);

        $this->seedBillingCatalog();
        $tenant = Tenant::factory()->create();

        app(SubscriptionService::class)->ensureDefaultSubscription($tenant->id);

        Event::assertDispatched(SubscriptionCreated::class, fn (SubscriptionCreated $e) => $e->tenantId === $tenant->id);
    }

    public function test_onboarding_creates_subscription_via_contract(): void
    {
        Event::fake([SubscriptionCreated::class]);
        $this->seedBillingCatalog();

        $platformUser = PlatformUser::create([
            'name' => 'Provisioner',
            'email' => 'provisioner-billing-'.uniqid().'@platform.test',
            'password' => Hash::make('password'),
            'is_active' => true,
        ]);
        $this->grantPlatformAccess($platformUser);

        $ownerEmail = 'billing-onboard-'.uniqid().'@example.com';

        $response = $this->actingAs($platformUser, 'platform')
            ->postJson('/platform/tenants', [
                'organization_name' => 'Billing Org',
                'owner_name' => 'Owner',
                'owner_email' => $ownerEmail,
                'owner_password' => 'password-Str0ng!',
                'isolation_level' => 'shared',
            ]);

        $response->assertCreated();
        $tenantId = $response->json('tenant_id');

        $this->assertDatabaseHas('subscriptions', [
            'tenant_id' => $tenantId,
            'status' => Subscription::STATUS_ACTIVE,
        ], 'central');

        Event::assertDispatched(SubscriptionCreated::class);
    }
}
