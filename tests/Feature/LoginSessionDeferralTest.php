<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Modules\Tenancy\Models\TenantDatabaseConfig;
use Tests\TestCase;

class LoginSessionDeferralTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['session.driver' => 'database']);
        config(['tenancy_storage.mode' => 'database_per_tenant']);

        if (app()->resolved('session')) {
            app()->forgetInstance('session');
        }
        if (app()->resolved('session.store')) {
            app()->forgetInstance('session.store');
        }
    }

    public function test_login_post_defers_session_to_dedicated_connection(): void
    {
        $user = $this->registerTenantUser('Login Defer User', 'login-defer-'.uniqid().'@example.com');
        $tenant = $user->personalTenant();
        $this->assertNotNull($tenant);

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

        $this->assignDashboardViewToUser($user, $tenant);
        $this->withoutMiddleware(\Modules\Identity\Http\Middleware\EnsureMfaVerified::class);

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertRedirect('/t/'.$tenant->id.'/dashboard');

        $this->assertSame('database', config('session.driver'));
        $this->assertSame($connection, config('session.connection'));
        $this->assertGreaterThan(0, DB::connection($connection)->table('sessions')->count());
    }
}
