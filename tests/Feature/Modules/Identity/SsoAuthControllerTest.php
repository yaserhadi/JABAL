<?php

namespace Tests\Feature\Modules\Identity;

use App\Models\User;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Identity\Models\Membership;
use Modules\Identity\Models\TenantUserIdentity;
use Modules\Identity\Services\SsoAuthService;
use Modules\Identity\Services\SsoConfigService;
use Modules\Identity\Support\Sso\LaravelSessionAuthSessionAdapter;
use Modules\Identity\Support\Sso\SsoAuthorizationState;
use Modules\Identity\Support\Sso\SsoIdentityResolutionResult;
use Modules\Tenancy\Models\Tenant;
use Modules\Tenancy\Models\TenantDatabaseConfig;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\GrantsSsoEntitlement;
use Tests\TestCase;

class SsoAuthControllerTest extends TestCase
{
    use GrantsSsoEntitlement;
    use \Tests\Support\SkipsPathEnterpriseSsoUnderHostProfile;

    protected function setUp(): void
    {
        parent::setUp();
        $this->skipPathEnterpriseSsoWhenHostProfile();
    }

    protected function createOrgTenantWithMember(): array
    {
        $tenant = Tenant::factory()->create();
        $this->grantSsoAvailable($tenant);

        tenancy()->initialize($tenant);
        $user = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'SSO User',
            'email' => 'sso-user-'.uniqid().'@example.com',
            'password' => 'password',
        ]);
        Membership::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'membership_type' => 'member',
            'status' => 'active',
            'joined_at' => now(),
        ]);
        tenancy()->end();

        return [$tenant, $user];
    }

    protected function enableSsoForTenant(Tenant $tenant): void
    {
        tenancy()->initialize($tenant);
        app(SsoConfigService::class)->update($tenant, [
            'enabled' => true,
            'issuer_url' => 'https://login.microsoftonline.com/tenant/v2.0',
            'client_id' => 'client-id',
            'client_secret' => 'client-secret',
        ]);
        tenancy()->end();
    }

    #[Test]
    public function redirect_rejects_when_sso_disabled(): void
    {
        [$tenant] = $this->createOrgTenantWithMember();

        $this->get('/t/'.$tenant->id.'/auth/sso/redirect')
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('email');
    }

    #[Test]
    public function redirect_rejects_when_entitlement_missing(): void
    {
        $tenant = Tenant::factory()->create();

        tenancy()->initialize($tenant);
        \Modules\Identity\Models\TenantSsoConfig::query()->create([
            'tenant_id' => $tenant->id,
            'enabled' => true,
            'disabled_by_entitlement' => false,
            'issuer_url' => 'https://login.microsoftonline.com/tenant/v2.0',
            'client_id' => 'client-id',
            'client_secret_encrypted' => \Illuminate\Support\Facades\Crypt::encryptString('client-secret'),
            'scopes' => ['openid', 'profile', 'email'],
        ]);
        tenancy()->end();

        $this->get('/t/'.$tenant->id.'/auth/sso/redirect')
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('email');
    }

    #[Test]
    public function redirect_stores_pkce_state_in_session(): void
    {
        [$tenant] = $this->createOrgTenantWithMember();
        $this->enableSsoForTenant($tenant);

        $this->mock(SsoAuthService::class, function ($mock) {
            $mock->shouldReceive('assertTenantMayStartSso')->andReturnNull();
            $mock->shouldReceive('buildAuthorizationRedirectUrl')->andReturnUsing(function (Tenant $tenant) {
                $pair = app(\Modules\Identity\Support\Sso\PkceS256Helper::class)->generatePair();
                $statePayload = SsoAuthorizationState::mint($tenant->id);
                $encodedState = SsoAuthorizationState::encode($statePayload);
                $adapter = new LaravelSessionAuthSessionAdapter(app('session.store'), $tenant->id);
                $adapter->initializeForAuthorization($pair['verifier'], $encodedState);

                return 'https://idp.example.com/authorize';
            });
        });

        $response = $this->get('/t/'.$tenant->id.'/auth/sso/redirect');
        $response->assertRedirect('https://idp.example.com/authorize');

        $session = $this->app['session.store'];
        $payload = $session->get(LaravelSessionAuthSessionAdapter::sessionKey($tenant->id));
        $this->assertIsArray($payload);
        $this->assertNotEmpty($payload['state'] ?? null);
        $this->assertNotEmpty($payload['code_verifier'] ?? null);
        $this->assertNotEmpty($payload['nonce'] ?? null);
    }

    #[Test]
    public function callback_rejects_tampered_state(): void
    {
        [$tenant] = $this->createOrgTenantWithMember();
        $this->enableSsoForTenant($tenant);

        $this->get('/auth/sso/callback?code=abc&state=invalid-state')
            ->assertForbidden();
    }

    #[Test]
    public function callback_rejects_expired_state(): void
    {
        [$tenant] = $this->createOrgTenantWithMember();
        $this->enableSsoForTenant($tenant);

        $encoded = SsoAuthorizationState::encode([
            'tenant_id' => $tenant->id,
            'csrf' => 'csrf',
            'exp' => now()->subMinute()->timestamp,
        ]);

        $this->get('/auth/sso/callback?code=abc&state='.urlencode($encoded))
            ->assertForbidden();
    }

    #[Test]
    public function callback_does_not_login_when_resolution_fails(): void
    {
        [$tenant] = $this->createOrgTenantWithMember();
        $this->enableSsoForTenant($tenant);

        $state = SsoAuthorizationState::encode(SsoAuthorizationState::mint($tenant->id));

        $this->mock(SsoAuthService::class, function ($mock) {
            $mock->shouldReceive('completeCallback')
                ->once()
                ->andReturn(SsoIdentityResolutionResult::failed(SsoIdentityResolutionResult::REASON_NO_MATCH));
        });

        $this->get('/auth/sso/callback?code=abc&state='.urlencode($state))
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('email');

        $this->assertGuest('web');
    }

    #[Test]
    public function callback_logs_in_and_regenerates_session_on_success(): void
    {
        [$tenant, $user] = $this->createOrgTenantWithMember();
        $this->enableSsoForTenant($tenant);
        $this->assignDashboardViewToUser($user, $tenant);

        $state = SsoAuthorizationState::encode(SsoAuthorizationState::mint($tenant->id));
        $issuer = 'https://login.microsoftonline.com/tenant/v2.0';
        $subject = 'sub-'.Str::uuid()->toString();

        tenancy()->initialize($tenant);
        $link = TenantUserIdentity::query()->create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'issuer' => $issuer,
            'subject' => $subject,
        ]);
        tenancy()->end();

        $this->mock(SsoAuthService::class, function ($mock) use ($user, $link) {
            $mock->shouldReceive('completeCallback')
                ->once()
                ->andReturn(SsoIdentityResolutionResult::success($user, $link, false));
        });

        $this->withoutMiddleware(\Modules\Identity\Http\Middleware\EnsureMfaVerified::class);

        $session = $this->app['session.store'];
        $beforeSessionId = $session->getId();

        $response = $this->get('/auth/sso/callback?code=abc&state='.urlencode($state));
        $response->assertRedirect($this->tenantDashboardRedirectUri($tenant));

        $this->assertAuthenticatedAs($user, 'web');
        $this->assertSame($tenant->id, session('tenant_id'));
        $this->assertNotSame($beforeSessionId, $session->getId());
    }

    #[Test]
    public function callback_rejects_tenant_mismatch_from_service(): void
    {
        [$tenant] = $this->createOrgTenantWithMember();
        $this->enableSsoForTenant($tenant);

        $state = SsoAuthorizationState::encode(SsoAuthorizationState::mint($tenant->id));

        $this->mock(SsoAuthService::class, function ($mock) {
            $mock->shouldReceive('completeCallback')
                ->once()
                ->andReturn(SsoIdentityResolutionResult::failed('tenant_mismatch'));
        });

        $this->get('/auth/sso/callback?code=abc&state='.urlencode($state))
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('email');

        $this->assertGuest('web');
    }

    #[Test]
    public function callback_response_does_not_expose_tokens(): void
    {
        [$tenant] = $this->createOrgTenantWithMember();
        $this->enableSsoForTenant($tenant);

        $state = SsoAuthorizationState::encode(SsoAuthorizationState::mint($tenant->id));
        $secretToken = 'super-secret-access-token-value';

        $this->mock(SsoAuthService::class, function ($mock) {
            $mock->shouldReceive('completeCallback')
                ->once()
                ->andReturn(SsoIdentityResolutionResult::failed(SsoIdentityResolutionResult::REASON_NO_MATCH));
        });

        $response = $this->get('/auth/sso/callback?code='.$secretToken.'&state='.urlencode($state));

        $response->assertRedirect(route('login'));
        $this->assertStringNotContainsString($secretToken, $response->getContent() ?? '');
    }

    #[Test]
    public function callback_uses_dedicated_session_connection_when_configured(): void
    {
        [$tenant, $user] = $this->createOrgTenantWithMember();
        $this->enableSsoForTenant($tenant);
        $this->assignDashboardViewToUser($user, $tenant);

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

        config(['session.driver' => 'database']);
        config(['tenancy_storage.mode' => 'database_per_tenant']);

        if (app()->resolved('session')) {
            app()->forgetInstance('session');
        }
        if (app()->resolved('session.store')) {
            app()->forgetInstance('session.store');
        }

        $state = SsoAuthorizationState::encode(SsoAuthorizationState::mint($tenant->id));

        $this->mock(SsoAuthService::class, function ($mock) use ($user) {
            $mock->shouldReceive('completeCallback')
                ->once()
                ->andReturn(SsoIdentityResolutionResult::success($user, new TenantUserIdentity, false));
        });

        $this->withoutMiddleware(\Modules\Identity\Http\Middleware\EnsureMfaVerified::class);

        $this->get('/auth/sso/callback?code=abc&state='.urlencode($state))
            ->assertRedirect($this->tenantDashboardRedirectUri($tenant));

        $this->assertSame($connection, config('session.connection'));
        $this->assertGreaterThan(0, DB::connection($connection)->table('sessions')->count());
    }

    #[Test]
    public function redirect_rejects_when_disabled_by_entitlement(): void
    {
        [$tenant] = $this->createOrgTenantWithMember();

        tenancy()->initialize($tenant);
        \Modules\Identity\Models\TenantSsoConfig::query()->create([
            'tenant_id' => $tenant->id,
            'enabled' => true,
            'disabled_by_entitlement' => true,
            'issuer_url' => 'https://login.microsoftonline.com/tenant/v2.0',
            'client_id' => 'client-id',
            'client_secret_encrypted' => \Illuminate\Support\Facades\Crypt::encryptString('client-secret'),
            'scopes' => ['openid', 'profile', 'email'],
        ]);
        tenancy()->end();

        $this->get('/t/'.$tenant->id.'/auth/sso/redirect')
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('email');
    }

    #[Test]
    public function callback_catches_security_exception_without_raw_error(): void
    {
        [$tenant] = $this->createOrgTenantWithMember();
        $this->enableSsoForTenant($tenant);

        $state = SsoAuthorizationState::encode(SsoAuthorizationState::mint($tenant->id));

        $this->mock(SsoAuthService::class, function ($mock) {
            $mock->shouldReceive('completeCallback')
                ->once()
                ->andThrow(new \Modules\Identity\Exceptions\SsoSecurityException('Tenant SSO is not enabled.'));
        });

        $response = $this->get('/auth/sso/callback?code=abc&state='.urlencode($state));
        $response->assertRedirect(route('login'))
            ->assertSessionHasErrors('email');

        $body = $response->getContent() ?? '';
        $this->assertStringNotContainsString('Tenant SSO is not enabled', $body);
        $this->assertGuest('web');
    }

    #[Test]
    public function controller_is_only_location_with_auth_login_for_sso(): void
    {
        $controller = file_get_contents(base_path('Modules/Identity/app/Http/Controllers/SsoAuthController.php'));
        $service = file_get_contents(base_path('Modules/Identity/app/Services/SsoAuthService.php'));

        $this->assertStringContainsString('Auth::guard', $controller);
        $this->assertStringNotContainsString('Auth::login(', $service);
    }
}
