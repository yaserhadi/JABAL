<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use Modules\Billing\Models\Entitlement;
use Modules\Billing\Models\Plan;
use Modules\Billing\Models\Subscription;
use Modules\Tenancy\Models\Tenant;

class BillingCatalogSeeder extends Seeder
{
    public function run(): void
    {
        $plan = Plan::query()->firstOrCreate(
            ['code' => 'standard'],
            [
                'name' => 'Standard',
                'description' => 'Default plan with MFA available',
                'is_active' => true,
                'seat_limit' => null,
            ]
        );

        foreach (['mfa_available', 'mfa_required'] as $code) {
            Entitlement::query()->firstOrCreate(
                ['plan_id' => $plan->id, 'code' => $code],
                ['name' => $code, 'is_active' => true]
            );
        }

        foreach (Tenant::query()->pluck('id') as $tenantId) {
            Subscription::query()->firstOrCreate(
                ['tenant_id' => $tenantId, 'status' => 'active'],
                [
                    'id' => Str::uuid()->toString(),
                    'plan_id' => $plan->id,
                    'starts_at' => now(),
                ]
            );
        }
    }
}
