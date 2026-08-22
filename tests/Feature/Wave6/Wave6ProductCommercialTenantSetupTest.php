<?php

namespace Tests\Feature\Wave6;

use App\Support\Contracts\Catalog\TenantCapabilityEvaluator;
use App\Support\Contracts\Tenancy\TenantSetupReadinessEvaluator;
use Illuminate\Support\Str;
use Modules\Billing\Models\Capability;
use Modules\Billing\Models\Offering;
use Modules\Billing\Models\Product;
use Modules\Billing\Services\ProductCatalogService;
use Modules\Billing\Services\SubscriptionService;
use Modules\Tenancy\Models\LegalOrganization;
use Modules\Tenancy\Models\LegalOrganizationBusinessOwner;
use Modules\Tenancy\Models\SetupDefinition;
use Modules\Tenancy\Models\Tenant;
use Modules\Tenancy\Models\TenantSetupState;
use Modules\Tenancy\Services\LegalOrganizationService;
use Modules\Tenancy\Services\SetupDefinitionCatalog;
use Modules\Tenancy\Services\TenantSetupReadinessService;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * WAVE-6: Product / Capability / Offering, Legal Org, Tenant Setup foundation.
 */
class Wave6ProductCommercialTenantSetupTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\BillingCatalogSeeder::class);
    }

    #[Test]
    public function product_and_capability_are_distinct(): void
    {
        $product = Product::query()->where('code', 'jabal')->firstOrFail();
        $capability = Capability::query()->where('code', 'sso')->firstOrFail();

        $this->assertNotSame($product->id, $capability->id);
        $this->assertNotEquals($product->code, $capability->code);
        $this->assertTrue($product->capabilities->contains('code', 'sso'));
    }

    #[Test]
    public function capability_is_not_a_spatie_permission(): void
    {
        $capability = Capability::query()->where('code', 'sso')->firstOrFail();
        $this->assertSame('sso_available', $capability->entitlement_code);
        $this->assertNotSame($capability->code, $capability->entitlement_code);
        // Catalog capability codes are not registered as tenant RBAC permission names.
        $rbac = app(\Modules\Tenancy\Services\TenantRbacProvisioner::class);
        $ref = new \ReflectionClass($rbac);
        $prop = $ref->getProperty('permissions');
        $prop->setAccessible(true);
        $permissionNames = $prop->getValue($rbac);
        $this->assertNotContains('sso', $permissionNames);
        $this->assertNotContains('user_management', $permissionNames);
    }

    #[Test]
    public function offering_includes_correct_capabilities(): void
    {
        $offering = Offering::query()->where('code', 'jabal-standard')->firstOrFail();
        $this->assertTrue($offering->isPublished());
        $codes = $offering->capabilities->pluck('code')->all();
        $this->assertContains('sso', $codes);
        $this->assertContains('user_management', $codes);
        $this->assertContains('mfa', $codes);
    }

    #[Test]
    public function tenant_entitlement_grants_capability_and_missing_denies(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);
        $offering = Offering::query()->where('code', 'jabal-standard')->firstOrFail();
        $tenant->forceFill(['offering_id' => $offering->id])->save();

        app(SubscriptionService::class)->ensureDefaultSubscription($tenant->id);

        $evaluator = app(TenantCapabilityEvaluator::class);
        $this->assertTrue($evaluator->tenantHasCapability($tenant->id, 'sso'));

        $bare = Tenant::factory()->create(['status' => 'active', 'offering_id' => $offering->id]);
        $this->assertFalse($evaluator->tenantHasCapability($bare->id, 'sso'));
    }

    #[Test]
    public function permission_alone_cannot_bypass_missing_entitlement_and_entitlement_alone_cannot_bypass_permission(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);
        $offering = Offering::query()->where('code', 'jabal-standard')->firstOrFail();
        $tenant->forceFill(['offering_id' => $offering->id])->save();

        $evaluator = app(TenantCapabilityEvaluator::class);

        // Permission true, entitlement missing
        $this->assertFalse($evaluator->capabilityAvailableAndAuthorized($tenant->id, 'sso', true));

        app(SubscriptionService::class)->ensureDefaultSubscription($tenant->id);

        // Entitlement true, permission false
        $this->assertFalse($evaluator->capabilityAvailableAndAuthorized($tenant->id, 'sso', false));
        // Both true
        $this->assertTrue($evaluator->capabilityAvailableAndAuthorized($tenant->id, 'sso', true));
    }

    #[Test]
    public function legal_organization_distinct_from_tenant_and_business_owner_references_user(): void
    {
        $tenant = Tenant::factory()->create(['name' => 'Ops Tenant']);
        $userId = (string) Str::uuid();

        $org = app(LegalOrganizationService::class)->create('Acme Legal LLC');
        app(LegalOrganizationService::class)->attachTenant($org, $tenant);
        $owner = app(LegalOrganizationService::class)->assignBusinessOwner($org, $userId, $tenant->id);

        $this->assertNotSame($org->id, $tenant->id);
        $this->assertSame($org->id, $tenant->fresh()->legal_organization_id);
        $this->assertSame($userId, $owner->user_id);
        $this->assertInstanceOf(LegalOrganizationBusinessOwner::class, $owner);

        // Changing business relationship does not invent a new user id
        $owner2 = app(LegalOrganizationService::class)->assignBusinessOwner($org, $userId, $tenant->id);
        $this->assertSame($userId, $owner2->user_id);
        $this->assertSame($owner->id, $owner2->id);
    }

    #[Test]
    public function setup_blocking_optional_conditional_and_active_ne_ready(): void
    {
        app(SetupDefinitionCatalog::class)->ensureDefaults();
        $tenant = Tenant::factory()->create([
            'status' => 'active',
            'setup_grandfathered' => false,
        ]);
        $offering = Offering::query()->where('code', 'jabal-standard')->firstOrFail();
        $tenant->forceFill(['offering_id' => $offering->id])->save();
        app(SubscriptionService::class)->ensureDefaultSubscription($tenant->id);
        app(TenantSetupReadinessService::class)->initializeForTenant($tenant->id);

        $readiness = app(TenantSetupReadinessEvaluator::class);

        $eval = $readiness->evaluate($tenant->id);
        $this->assertSame('active', $tenant->status);
        $this->assertFalse($eval['ready']);
        $this->assertContains('company_profile', $eval['blocking_incomplete']);

        // Optional incomplete does not appear in blocking
        $this->assertNotContains('business_owner_confirmation', $eval['blocking_incomplete']);

        // Conditional SSO applicable because entitlement present → blocks when pending
        $this->assertContains('sso_configuration', $eval['blocking_incomplete']);

        app(TenantSetupReadinessService::class)->complete($tenant->id, 'company_profile');
        app(TenantSetupReadinessService::class)->complete($tenant->id, 'sso_configuration');

        $this->assertTrue($readiness->isOperationallyReady($tenant->id));
        $this->assertSame('active', $tenant->fresh()->status);
    }

    #[Test]
    public function conditional_non_applicable_does_not_block_and_applicability_follows_entitlement(): void
    {
        app(SetupDefinitionCatalog::class)->ensureDefaults();
        $tenant = Tenant::factory()->create([
            'status' => 'active',
            'setup_grandfathered' => false,
            'offering_id' => Offering::query()->where('code', 'jabal-standard')->value('id'),
        ]);
        // No subscription → no sso_available → conditional SSO not applicable
        app(TenantSetupReadinessService::class)->initializeForTenant($tenant->id);
        app(TenantSetupReadinessService::class)->complete($tenant->id, 'company_profile');

        $eval = app(TenantSetupReadinessEvaluator::class)->evaluate($tenant->id);
        $this->assertTrue($eval['ready']);
        $applicableCodes = collect($eval['applicable'])->pluck('code')->all();
        $this->assertNotContains('sso_configuration', $applicableCodes);
    }

    #[Test]
    public function setup_completion_does_not_alter_roles_and_definition_version_preserves_history(): void
    {
        app(SetupDefinitionCatalog::class)->ensureDefaults();
        $tenant = Tenant::factory()->create(['setup_grandfathered' => false]);
        app(TenantSetupReadinessService::class)->initializeForTenant($tenant->id);
        app(TenantSetupReadinessService::class)->complete($tenant->id, 'company_profile', null, ['note' => 'v1']);

        $v1 = SetupDefinition::query()->where('code', 'company_profile')->where('version', 1)->firstOrFail();
        $state = TenantSetupState::query()
            ->where('tenant_id', $tenant->id)
            ->where('setup_definition_id', $v1->id)
            ->firstOrFail();
        $this->assertSame(TenantSetupState::STATUS_COMPLETED, $state->status);
        $this->assertSame(1, $state->definition_version);
        $this->assertSame(['note' => 'v1'], $state->evidence);

        // New version does not erase v1 state row
        SetupDefinition::query()->create([
            'code' => 'company_profile',
            'version' => 2,
            'title' => 'Company profile v2',
            'requirement_type' => SetupDefinition::TYPE_BLOCKING,
            'is_active' => true,
        ]);

        $this->assertTrue(
            TenantSetupState::query()
                ->where('tenant_id', $tenant->id)
                ->where('setup_definition_id', $v1->id)
                ->where('status', TenantSetupState::STATUS_COMPLETED)
                ->exists()
        );
    }

    #[Test]
    public function existing_tenant_backfill_grandfathers_readiness(): void
    {
        $tenant = Tenant::factory()->create([
            'status' => 'active',
            'setup_grandfathered' => false,
            'legal_organization_id' => null,
            'offering_id' => null,
        ]);

        $this->artisan('wave6:backfill-existing-tenants')->assertSuccessful();

        $tenant->refresh();
        $this->assertTrue($tenant->setup_grandfathered);
        $this->assertNotNull($tenant->legal_organization_id);
        $this->assertNotNull($tenant->offering_id);
        $this->assertTrue(app(TenantSetupReadinessEvaluator::class)->isOperationallyReady($tenant->id));
        $this->assertInstanceOf(LegalOrganization::class, $tenant->legalOrganization);
    }

    #[Test]
    public function catalog_ensure_is_idempotent(): void
    {
        $a = app(ProductCatalogService::class)->ensureDefaultCatalog();
        $b = app(ProductCatalogService::class)->ensureDefaultCatalog();
        $this->assertSame($a->id, $b->id);
        $this->assertSame(1, Offering::query()->where('code', 'jabal-standard')->count());
    }
}
