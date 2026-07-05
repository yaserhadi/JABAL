<?php

namespace Tests\Feature\Modules\Identity;

use App\Http\Middleware\ConfigureApplicationRuntime;
use Illuminate\Auth\Events\Login;
use Illuminate\Http\Request;
use Modules\Identity\Listeners\RegisterSessionOnLogin;
use Modules\Identity\Services\SessionRegistryService;
use Tests\Concerns\InteractsWithDedicatedTenantDatabase;
use Tests\TestCase;

/** BK-053 / BK-042 — session registry on dedicated physical DB. */
class DedicatedStorageSessionRegistryTest extends TestCase
{
    use InteractsWithDedicatedTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpDedicatedTenantMode();
    }

    public function test_session_registry_register_persists_on_dedicated_db(): void
    {
        $email = 'ded-sess-reg-'.uniqid().'@example.com';
        [$tenant, $user, $connection] = $this->createDedicatedUserFixture($email);
        $sessionId = 'ded-sess-reg-'.uniqid();

        tenancy()->initialize($tenant);
        try {
            $request = Request::create('/login', 'POST');
            $request->server->set('REMOTE_ADDR', '10.0.0.1');
            $request->headers->set('User-Agent', 'DedicatedTest/1.0');

            $record = app(SessionRegistryService::class)->register($user, $request, $sessionId);

            $this->assertTableRowOnDedicatedNotShared('user_sessions', [
                'session_id' => $sessionId,
                'user_id' => $user->id,
                'tenant_id' => $tenant->id,
            ], $connection);

            $this->assertSame($sessionId, $record->session_id);
        } finally {
            tenancy()->end();
        }
    }

    public function test_login_listener_registers_session_on_dedicated_db(): void
    {
        $email = 'ded-sess-listener-'.uniqid().'@example.com';
        [$tenant, $user, $connection] = $this->createDedicatedUserFixture($email);

        tenancy()->initialize($tenant);
        try {
            $this->actingAsTenant($tenant);

            $request = request();
            $request->attributes->set(ConfigureApplicationRuntime::ATTRIBUTE, 'tenant');
            $request->setLaravelSession(app('session.store'));
            $request->session()->start();

            $sessionId = $request->session()->getId();

            $event = new Login('web', $user, false);
            app(RegisterSessionOnLogin::class)->handle($event);

            $this->assertTableRowOnDedicatedNotShared('user_sessions', [
                'session_id' => $sessionId,
                'user_id' => $user->id,
                'tenant_id' => $tenant->id,
            ], $connection);
        } finally {
            tenancy()->end();
        }
    }
}
