<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Billing\Models\Entitlement;
use Modules\Billing\Models\Plan;

/**
 * Product catalog only — plans and entitlements (DEC-0013).
 *
 * NEVER creates subscription rows. Use billing:bootstrap-subscriptions for tenant subscriptions.
 */
class BillingCatalogSeeder extends Seeder
{
    public function run(): void
    {
        $plan = Plan::query()->firstOrCreate(
            ['code' => Plan::DEFAULT_CODE],
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
    }
}
