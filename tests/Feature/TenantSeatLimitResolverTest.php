<?php

namespace Tests\Feature;

use App\Support\Contracts\Billing\TenantSeatLimitResolver;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Modules\Billing\Models\Plan;
use Modules\Billing\Models\Subscription;
use Modules\Billing\Services\DatabaseTenantSeatLimitResolver;
use Modules\Identity\Services\MembershipService;
use Modules\Tenancy\Models\Tenant;
use Tests\TestCase;

class TenantSeatLimitResolverTest extends TestCase
{
    public function test_resolver_returns_subscription_seat_limit_via_contract(): void
    {
        $tenant = Tenant::factory()->create();
        $plan = Plan::query()->create([
            'id' => Str::uuid()->toString(),
            'code' => 'limited',
            'name' => 'Limited',
            'is_active' => true,
            'seat_limit' => 10,
        ]);

        Subscription::query()->create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $tenant->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'seat_limit' => 2,
            'starts_at' => now(),
        ]);

        /** @var TenantSeatLimitResolver $resolver */
        $resolver = app(TenantSeatLimitResolver::class);
        $this->assertInstanceOf(DatabaseTenantSeatLimitResolver::class, $resolver);
        $this->assertSame(2, $resolver->seatLimitForTenant($tenant->id));
    }

    public function test_membership_create_enforces_seat_limit(): void
    {
        $owner = $this->registerTenantUser('Owner', 'owner-'.uniqid().'@example.com');
        $tenant = $owner->personalTenant();

        $plan = Plan::query()->create([
            'id' => Str::uuid()->toString(),
            'code' => 'solo',
            'name' => 'Solo',
            'is_active' => true,
            'seat_limit' => 1,
        ]);

        Subscription::query()->create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $tenant->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'starts_at' => now(),
        ]);

        $member = \Modules\Identity\Models\TenantUser::factory()->create(['tenant_id' => $tenant->id]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Seat limit reached');

        app(MembershipService::class)->create($member->id, $tenant->id, 'member', 'active');
    }
}
