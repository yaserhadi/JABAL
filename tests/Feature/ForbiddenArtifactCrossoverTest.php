<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * F7 — Forbidden artifact crossover (ADR-0007 §3.1.2).
 */
class ForbiddenArtifactCrossoverTest extends TestCase
{
    public function test_platform_sessions_table_on_central_not_tenant(): void
    {
        $this->assertTrue(Schema::connection('central')->hasTable('platform_sessions'));
        $this->assertFalse(Schema::connection('tenant')->hasTable('platform_sessions'));
    }

    public function test_tenant_sessions_table_on_tenant_not_central(): void
    {
        $this->assertTrue(Schema::connection('tenant')->hasTable('sessions'));
        $this->assertFalse(Schema::connection('central')->hasTable('sessions'));
    }

    public function test_platform_rbac_tables_on_central_only(): void
    {
        foreach (['platform_roles', 'platform_permissions', 'platform_role_has_permissions'] as $table) {
            $this->assertTrue(Schema::connection('central')->hasTable($table), "Missing central {$table}");
            $this->assertFalse(Schema::connection('tenant')->hasTable($table), "Tenant must not have {$table}");
        }
    }

    public function test_tenant_spatie_rbac_primary_on_tenant_layer(): void
    {
        foreach (['roles', 'permissions', 'model_has_roles'] as $table) {
            $this->assertTrue(Schema::connection('tenant')->hasTable($table));
        }
    }

    public function test_legacy_central_tenant_users_dropped(): void
    {
        $this->assertFalse(Schema::connection('central')->hasTable('tenant_users'));
    }

    public function test_legacy_central_spatie_rbac_dropped(): void
    {
        foreach (['roles', 'permissions', 'model_has_roles'] as $table) {
            $this->assertFalse(Schema::connection('central')->hasTable($table), "Central must not have legacy {$table}");
        }
    }

    public function test_memberships_on_tenant_not_central(): void
    {
        $this->assertTrue(Schema::connection('tenant')->hasTable('memberships'));
        $this->assertFalse(Schema::connection('central')->hasTable('memberships'));
    }

    public function test_tenant_contacts_on_central_not_tenant(): void
    {
        $this->assertTrue(Schema::connection('central')->hasTable('tenant_contacts'));
        $this->assertFalse(Schema::connection('tenant')->hasTable('tenant_contacts'));
    }
}
