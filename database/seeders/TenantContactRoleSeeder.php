<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Tenancy\Models\TenantContactRole;

class TenantContactRoleSeeder extends Seeder
{
    /** @var list<array{code: string, name: string, sort_order: int}> */
    public const ROLES = [
        ['code' => 'account_owner', 'name' => 'Account Owner', 'sort_order' => 10],
        ['code' => 'billing', 'name' => 'Billing', 'sort_order' => 20],
        ['code' => 'technical', 'name' => 'Technical', 'sort_order' => 30],
        ['code' => 'legal', 'name' => 'Legal', 'sort_order' => 40],
        ['code' => 'authorized_signatory', 'name' => 'Authorized Signatory', 'sort_order' => 50],
        ['code' => 'operations', 'name' => 'Operations', 'sort_order' => 60],
        ['code' => 'renewal', 'name' => 'Renewal', 'sort_order' => 70],
        ['code' => 'security', 'name' => 'Security', 'sort_order' => 80],
        ['code' => 'procurement', 'name' => 'Procurement', 'sort_order' => 90],
        ['code' => 'executive_sponsor', 'name' => 'Executive Sponsor', 'sort_order' => 100],
        ['code' => 'implementation_lead', 'name' => 'Implementation Lead', 'sort_order' => 110],
        ['code' => 'support_escalation', 'name' => 'Support Escalation', 'sort_order' => 120],
    ];

    public function run(): void
    {
        foreach (self::ROLES as $role) {
            TenantContactRole::firstOrCreate(
                ['code' => $role['code']],
                [
                    'name' => $role['name'],
                    'is_system' => true,
                    'is_active' => true,
                    'sort_order' => $role['sort_order'],
                ]
            );
        }
    }
}
