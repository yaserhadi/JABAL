<?php

namespace Tests\Feature;

use App\Models\PlatformUser;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * F8 — No cross-database auth dependency (ADR-0007, Wave 1 foundation report).
 */
class CrossDatabaseAuthDependencyTest extends TestCase
{
    public function test_platform_login_does_not_require_tenant_membership(): void
    {
        $email = 'plat-f8-'.uniqid().'@test.com';
        $user = PlatformUser::create([
            'name' => 'Platform Only',
            'email' => $email,
            'password' => Hash::make('password'),
            'is_active' => true,
        ]);
        $this->grantPlatformAccess($user);

        $response = $this->post('/platform/login', [
            'email' => $email,
            'password' => 'password',
        ]);

        $response->assertRedirect(route('platform.settings.index', absolute: false));
        $this->assertAuthenticated('platform');
    }

    public function test_tenant_registration_does_not_create_platform_user(): void
    {
        $email = 'tenant-f8-'.uniqid().'@example.com';
        $this->registerTenantUser('F8 User', $email);

        $this->assertDatabaseMissing('platform_users', ['email' => $email], 'central');
    }

    public function test_dedicated_tenant_user_not_stored_on_shared_connection_when_fixture_uses_database_isolation(): void
    {
        config(['tenancy_storage.mode' => 'database_per_tenant']);
        $databaseName = 'jabal_tenant_dedicated_a_testing';
        $exists = \Illuminate\Support\Facades\DB::connection('central')->selectOne(
            'SELECT 1 FROM pg_database WHERE datname = ?',
            [$databaseName]
        );
        if (! $exists) {
            $this->markTestSkipped('Dedicated test database missing.');
        }

        $tenantId = 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa';
        $email = 'f8-ded-'.uniqid().'@example.com';
        $tenant = \Modules\Tenancy\Models\Tenant::query()->find($tenantId) ?? new \Modules\Tenancy\Models\Tenant;
        if (! $tenant->exists) {
            $tenant->id = $tenantId;
            $tenant->forceFill([
                'name' => 'F8 Dedicated',
                'slug' => 'f8-ded',
                'isolation_level' => 'database',
                'status' => 'active',
            ])->save();
        }

        \Modules\Tenancy\Models\TenantDatabaseConfig::query()->updateOrCreate(
            ['tenant_id' => $tenant->id],
            ['isolation_level' => 'database', 'database_name' => $databaseName, 'provisioning_status' => 'active']
        );

        $connection = 'tenant_db_'.$tenant->id;
        \Illuminate\Support\Facades\Config::set('database.connections.'.$connection, array_merge(
            config('database.connections.tenant'),
            ['database' => $databaseName]
        ));

        tenancy()->initialize($tenant->fresh(['databaseConfig']));
        try {
            $user = \Modules\Identity\Models\TenantUser::create([
                'tenant_id' => $tenant->id,
                'name' => 'F8 Dedicated User',
                'email' => $email,
                'password' => 'password',
            ]);
        } finally {
            tenancy()->end();
        }

        $this->assertTrue(
            \Illuminate\Support\Facades\DB::connection($connection)->table('users')->where('id', $user->id)->exists()
        );
        $this->assertFalse(
            \Illuminate\Support\Facades\DB::connection('tenant')->table('users')->where('email', $email)->exists()
        );
    }
}
