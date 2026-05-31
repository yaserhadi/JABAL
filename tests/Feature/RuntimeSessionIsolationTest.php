<?php

namespace Tests\Feature;

use App\Http\Middleware\ConfigureApplicationRuntime;
use App\Models\PlatformUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class RuntimeSessionIsolationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // phpunit.xml defaults to array driver; persistence tests need database sessions.
        config(['session.driver' => 'database']);
        if (app()->resolved('session')) {
            app()->forgetInstance('session');
        }
        if (app()->resolved('session.store')) {
            app()->forgetInstance('session.store');
        }
    }

    public function test_platform_route_resolves_session_connection_to_central(): void
    {
        $response = $this->get('/platform/login');

        $response->assertOk();
        $this->assertSame('central', config('session.connection'));
        $this->assertSame('platform_sessions', config('session.table'));
        $this->assertSame(
            config('session.profiles.platform.cookie'),
            config('session.cookie')
        );
    }

    public function test_tenant_login_route_resolves_session_connection_to_tenant(): void
    {
        $response = $this->get('/login');

        $response->assertOk();
        $this->assertSame('tenant', config('session.connection'));
        $this->assertSame('sessions', config('session.table'));
        $this->assertSame(
            config('session.profiles.tenant.cookie'),
            config('session.cookie')
        );
    }

    public function test_platform_and_tenant_session_cookies_are_distinct(): void
    {
        $this->assertNotSame(
            config('session.profiles.platform.cookie'),
            config('session.profiles.tenant.cookie')
        );
    }

    public function test_platform_login_persists_session_in_central_platform_sessions_only(): void
    {
        PlatformUser::create([
            'name' => 'Session Admin',
            'email' => 'sess-'.uniqid().'@platform.test',
            'password' => Hash::make('password'),
            'is_active' => true,
        ]);

        $email = PlatformUser::query()->latest('created_at')->value('email');

        $this->post('/platform/login', [
            'email' => $email,
            'password' => 'password',
        ])->assertRedirect(route('platform.settings.index', absolute: false));

        $this->assertTrue(
            Schema::connection('central')->hasTable('platform_sessions'),
            'platform_sessions table must exist on central'
        );

        $centralCount = DB::connection('central')->table('platform_sessions')->count();
        $this->assertGreaterThan(0, $centralCount);

        $tenantSessionsWithPlatformUser = DB::connection('tenant')
            ->table('sessions')
            ->whereNotNull('user_id')
            ->count();

        $this->assertSame(0, $tenantSessionsWithPlatformUser);
    }

    public function test_application_runtime_attribute_on_platform_request(): void
    {
        $this->get('/platform/login');

        $this->assertSame('platform', request()->attributes->get(ConfigureApplicationRuntime::ATTRIBUTE));
    }

    public function test_application_runtime_attribute_on_tenant_request(): void
    {
        $this->get('/login');

        $this->assertSame('tenant', request()->attributes->get(ConfigureApplicationRuntime::ATTRIBUTE));
    }
}
