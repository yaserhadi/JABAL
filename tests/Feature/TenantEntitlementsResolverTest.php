<?php

namespace Tests\Feature;

use App\Support\Contracts\Billing\TenantEntitlementsResolver;
use Illuminate\Support\Str;
use Modules\Billing\Models\Entitlement;
use Modules\Billing\Models\Plan;
use Modules\Billing\Models\Subscription;
use Modules\Billing\Services\DatabaseTenantEntitlementsResolver;
use Modules\Tenancy\Models\Tenant;
use Tests\TestCase;

/** Contract-only validation — consumers must not import Billing models at boundaries. */
class TenantEntitlementsResolverTest extends TestCase
{
    public function test_resolver_returns_mfa_entitlements_via_contract(): void
    {
        $tenant = Tenant::factory()->create();
        $plan = Plan::query()->create([
            'id' => Str::uuid()->toString(),
            'code' => 'enterprise',
            'name' => 'Enterprise',
            'is_active' => true,
        ]);

        foreach (['mfa_available', 'mfa_required'] as $code) {
            Entitlement::query()->create([
                'id' => Str::uuid()->toString(),
                'plan_id' => $plan->id,
                'code' => $code,
                'name' => $code,
                'is_active' => true,
            ]);
        }

        Subscription::query()->create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $tenant->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'starts_at' => now(),
        ]);

        /** @var TenantEntitlementsResolver $resolver */
        $resolver = app(TenantEntitlementsResolver::class);
        $this->assertInstanceOf(DatabaseTenantEntitlementsResolver::class, $resolver);

        $codes = $resolver->entitlementsForTenant($tenant->id);
        $this->assertContains('mfa_available', $codes);
        $this->assertContains('mfa_required', $codes);
        $this->assertTrue($resolver->tenantHasEntitlement($tenant->id, 'mfa_available'));
    }
}
