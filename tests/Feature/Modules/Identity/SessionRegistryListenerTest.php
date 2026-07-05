<?php

namespace Tests\Feature\Modules\Identity;

use App\Http\Middleware\ConfigureApplicationRuntime;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Modules\Identity\Listeners\DeregisterSessionOnLogout;
use Modules\Identity\Listeners\RegisterSessionOnLogin;
use Modules\Identity\Models\UserSession;
use Tests\TestCase;

class SessionRegistryListenerTest extends TestCase
{
    public function test_login_event_registers_session_in_tenant_context(): void
    {
        $user = $this->registerTenantUser('Login Listener', 'sess-ll-'.uniqid().'@example.com');
        $tenant = $user->personalTenant();
        $this->actingAsTenant($tenant);

        $request = request();
        $request->attributes->set(ConfigureApplicationRuntime::ATTRIBUTE, 'tenant');
        $request->setLaravelSession(app('session.store'));
        $request->session()->start();

        $event = new Login('web', $user, false);

        $listener = app(RegisterSessionOnLogin::class);
        $listener->handle($event);

        $this->assertDatabaseHas('user_sessions', [
            'user_id' => $user->id,
            'tenant_id' => $tenant->id,
        ], 'tenant');
    }

    public function test_logout_event_revokes_session_in_tenant_context(): void
    {
        $user = $this->registerTenantUser('Logout Listener', 'sess-lo-'.uniqid().'@example.com');
        $tenant = $user->personalTenant();
        $this->actingAsTenant($tenant);

        $request = request();
        $request->attributes->set(ConfigureApplicationRuntime::ATTRIBUTE, 'tenant');
        $request->setLaravelSession(app('session.store'));
        $request->session()->start();

        $sessionId = $request->session()->getId();

        $record = UserSession::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'session_id' => $sessionId,
            'last_activity_at' => now(),
            'logged_in_at' => now(),
        ]);

        $event = new Logout('web', $user);

        $listener = app(DeregisterSessionOnLogout::class);
        $listener->handle($event);

        $record->refresh();
        $this->assertNotNull($record->revoked_at);
    }

    public function test_login_event_no_ops_for_platform_context(): void
    {
        $user = $this->registerTenantUser('Platform Login', 'sess-plat-'.uniqid().'@example.com');
        $tenant = $user->personalTenant();
        $this->actingAsTenant($tenant);

        $request = request();
        $request->attributes->set(ConfigureApplicationRuntime::ATTRIBUTE, 'platform');

        $event = new Login('web', $user, false);

        $listener = app(RegisterSessionOnLogin::class);
        $listener->handle($event);

        $this->assertDatabaseMissing('user_sessions', [
            'user_id' => $user->id,
        ], 'tenant');
    }

    public function test_logout_event_no_ops_for_platform_context(): void
    {
        $user = $this->registerTenantUser('Platform Logout', 'sess-plat-lo-'.uniqid().'@example.com');
        $tenant = $user->personalTenant();
        $this->actingAsTenant($tenant);

        $request = request();
        $request->attributes->set(ConfigureApplicationRuntime::ATTRIBUTE, 'platform');

        UserSession::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'session_id' => 'platform-sess',
            'last_activity_at' => now(),
            'logged_in_at' => now(),
        ]);

        $event = new Logout('web', $user);

        $listener = app(DeregisterSessionOnLogout::class);
        $listener->handle($event);

        $record = UserSession::where('session_id', 'platform-sess')->first();
        $this->assertNull($record->revoked_at);
    }

    public function test_logout_event_no_ops_gracefully_when_no_matching_record(): void
    {
        $user = $this->registerTenantUser('NoMatch Logout', 'sess-nomatch-'.uniqid().'@example.com');
        $tenant = $user->personalTenant();
        $this->actingAsTenant($tenant);

        $request = request();
        $request->attributes->set(ConfigureApplicationRuntime::ATTRIBUTE, 'tenant');
        $request->setLaravelSession(app('session.store'));
        $request->session()->start();

        $event = new Logout('web', $user);

        $listener = app(DeregisterSessionOnLogout::class);
        $listener->handle($event);

        $this->assertDatabaseMissing('user_sessions', [
            'user_id' => $user->id,
        ], 'tenant');
    }
}
