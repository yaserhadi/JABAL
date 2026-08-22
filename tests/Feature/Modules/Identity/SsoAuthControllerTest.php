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
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\GrantsSsoEntitlement;
use Tests\TestCase;

class SsoAuthControllerTest extends TestCase
{
    use GrantsSsoEntitlement;

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
            'approved_email_domains' => ['example.com'],
        ]);
        tenancy()->end();
    }

    /**
     * Session-matrix helpers: skip runtime cookie/instance reset so actingAs + withSession
     * remain visible to the Path SSO callback (mirrors Host handoff test style).
     *
     * @return list<class-string>
     */
    protected function pathSsoSessionMatrixMiddlewareToSkip(): array
    {
        return [
            \App\Http\Middleware\ConfigureApplicationRuntime::class,
            \App\Http\Middleware\ConfigureTenantSessionConnection::class,
        ];
    }

    #[Test]
    #[Group('path-profile-contract')]
    public function redirect_rejects_when_sso_disabled(): void
    {
        [$tenant] = $this->createOrgTenantWithMember();

        $this->get('/t/'.$tenant->id.'/auth/sso/redirect')
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('email');
    }

    #[Test]
    #[Group('path-profile-contract')]
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
            'scopes' => ['openid', 'profile', 'email'],
        ]);
        tenancy()->end();

        $this->get('/t/'.$tenant->id.'/auth/sso/redirect')
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('email');
    }

    #[Test]
    #[Group('path-profile-contract')]
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
    #[Group('path-profile-contract')]
    public function callback_rejects_tampered_state(): void
    {
        [$tenant] = $this->createOrgTenantWithMember();
        $this->enableSsoForTenant($tenant);

        $this->get('/auth/sso/callback?code=abc&state=invalid-state')
            ->assertForbidden();
    }

    #[Test]
    #[Group('path-profile-contract')]
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
    #[Group('path-profile-contract')]
    public function callback_does_not_login_when_resolution_fails(): void
    {
        [$tenant] = $this->createOrgTenantWithMember();
        $this->enableSsoForTenant($tenant);

        $state = SsoAuthorizationState::encode(SsoAuthorizationState::mint($tenant->id));

        $this->mock(SsoAuthService::class, function ($mock) {
            $mock->shouldReceive('completeCallback')
                ->once()
                ->andReturn(SsoIdentityResolutionResult::failed(SsoIdentityResolutionResult::REASON_IDENTITY_NOT_PROVISIONED));
        });

        $this->get('/auth/sso/callback?code=abc&state='.urlencode($state))
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('email');

        $this->assertGuest('web');
    }

    #[Test]
    #[Group('path-profile-contract')]
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
                ->andReturn(SsoIdentityResolutionResult::success($user, $link));
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
    #[Group('path-profile-contract')]
    public function callback_same_user_ordinary_continuation_does_not_regenerate_or_relogin(): void
    {
        [$tenant, $user] = $this->createOrgTenantWithMember();
        $this->enableSsoForTenant($tenant);
        $this->assignDashboardViewToUser($user, $tenant);

        tenancy()->initialize($tenant);
        $link = TenantUserIdentity::query()->create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'issuer' => 'https://login.microsoftonline.com/tenant/v2.0',
            'subject' => 'sub-'.Str::uuid()->toString(),
        ]);
        $versionId = app(\Modules\Identity\Services\SsoConfigService::class)->getActiveVersionId($tenant);
        app(\Modules\Identity\Support\Sso\SsoIdentityLifecycle::class)->markLinked(
            $link,
            (string) $tenant->id,
            $versionId,
        );
        app(\Modules\Identity\Support\Sso\SsoIdentityLifecycle::class)->markLoginVerifiedAndReady(
            $link->fresh(),
            $user,
            (string) $tenant->id,
            (string) $versionId,
            'test_seed_ready',
        );
        $link = $link->fresh();
        tenancy()->end();

        $state = SsoAuthorizationState::encode(SsoAuthorizationState::mint($tenant->id));

        $this->mock(SsoAuthService::class, function ($mock) use ($user, $link) {
            $mock->shouldReceive('completeCallback')
                ->once()
                ->andReturn(SsoIdentityResolutionResult::success($user, $link));
        });

        $this->withoutMiddleware([
            ...$this->pathSsoSessionMatrixMiddlewareToSkip(),
            \Modules\Identity\Http\Middleware\EnsureMfaVerified::class,
        ]);

        $this->actingAs($user, 'web');
        $this->withSession(['tenant_id' => $tenant->id, 'mfa_verified_at' => 'preserve-me']);

        $this->get('/auth/sso/callback?code=abc&state='.urlencode($state))
            ->assertRedirect($this->tenantDashboardRedirectUri($tenant));

        $this->assertAuthenticatedAs($user, 'web');
        // Prove no regenerate/re-login when already Ready: MFA marker and tenant binding survive.
        $this->assertSame('preserve-me', session('mfa_verified_at'));
        $this->assertSame($tenant->id, session('tenant_id'));
    }

    #[Test]
    #[Group('path-profile-contract')]
    public function callback_linked_not_ready_same_user_regenerates_and_marks_ready(): void
    {
        [$tenant, $user] = $this->createOrgTenantWithMember();
        $this->enableSsoForTenant($tenant);
        $this->assignDashboardViewToUser($user, $tenant);

        tenancy()->initialize($tenant);
        $link = TenantUserIdentity::query()->create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'issuer' => 'https://login.microsoftonline.com/tenant/v2.0',
            'subject' => 'sub-'.Str::uuid()->toString(),
        ]);
        $versionId = app(\Modules\Identity\Services\SsoConfigService::class)->getActiveVersionId($tenant);
        app(\Modules\Identity\Support\Sso\SsoIdentityLifecycle::class)->markLinked(
            $link,
            (string) $tenant->id,
            $versionId,
        );
        $link = $link->fresh();
        tenancy()->end();

        $state = SsoAuthorizationState::encode(SsoAuthorizationState::mint($tenant->id));

        $this->mock(SsoAuthService::class, function ($mock) use ($user, $link) {
            $mock->shouldReceive('completeCallback')
                ->once()
                ->andReturn(SsoIdentityResolutionResult::success($user, $link));
        });

        $this->withoutMiddleware([
            ...$this->pathSsoSessionMatrixMiddlewareToSkip(),
            \Modules\Identity\Http\Middleware\EnsureMfaVerified::class,
        ]);

        $this->actingAs($user, 'web');
        $this->withSession(['tenant_id' => $tenant->id, 'mfa_verified_at' => 'stale-enrollment-context']);

        $before = $this->app['session.store']->getId();

        $this->get('/auth/sso/callback?code=abc&state='.urlencode($state))
            ->assertRedirect($this->tenantDashboardRedirectUri($tenant));

        $this->assertAuthenticatedAs($user, 'web');
        $this->assertNotSame($before, $this->app['session.store']->getId());
        tenancy()->initialize($tenant);
        $this->assertSame(
            \Modules\Identity\Support\Sso\SsoIdentityLifecycle::STATUS_READY,
            $link->fresh()->verification_status
        );
        $this->assertSame((string) $user->id, (string) $link->fresh()->user_id);
        tenancy()->end();
    }

    #[Test]
    #[Group('path-profile-contract')]
    public function callback_different_user_denies_and_preserves_original_principal(): void
    {
        [$tenant, $user] = $this->createOrgTenantWithMember();
        $this->enableSsoForTenant($tenant);

        tenancy()->initialize($tenant);
        $other = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Other User',
            'email' => 'other-'.uniqid().'@example.com',
            'password' => 'password',
        ]);
        Membership::create([
            'tenant_id' => $tenant->id,
            'user_id' => $other->id,
            'membership_type' => 'member',
            'status' => 'active',
            'joined_at' => now(),
        ]);
        $otherLink = TenantUserIdentity::query()->create([
            'tenant_id' => $tenant->id,
            'user_id' => $other->id,
            'issuer' => 'https://login.microsoftonline.com/tenant/v2.0',
            'subject' => 'sub-'.Str::uuid()->toString(),
        ]);
        tenancy()->end();

        $state = SsoAuthorizationState::encode(SsoAuthorizationState::mint($tenant->id));

        $this->mock(SsoAuthService::class, function ($mock) use ($other, $otherLink) {
            $mock->shouldReceive('completeCallback')
                ->once()
                ->andReturn(SsoIdentityResolutionResult::success($other, $otherLink));
        });

        $this->withoutMiddleware([
            ...$this->pathSsoSessionMatrixMiddlewareToSkip(),
            \Modules\Identity\Http\Middleware\EnsureMfaVerified::class,
        ]);

        $this->actingAs($user, 'web');
        $this->withSession(['tenant_id' => $tenant->id]);

        $this->get('/auth/sso/callback?code=abc&state='.urlencode($state))
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('email');

        $this->assertAuthenticatedAs($user, 'web');
        $this->assertSame($tenant->id, session('tenant_id'));
    }

    #[Test]
    #[Group('path-profile-contract')]
    public function callback_same_user_wrong_tenant_binding_denies_and_preserves_principal(): void
    {
        [$tenant, $user] = $this->createOrgTenantWithMember();
        $this->enableSsoForTenant($tenant);

        tenancy()->initialize($tenant);
        $link = TenantUserIdentity::query()->create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'issuer' => 'https://login.microsoftonline.com/tenant/v2.0',
            'subject' => 'sub-'.Str::uuid()->toString(),
        ]);
        tenancy()->end();

        $state = SsoAuthorizationState::encode(SsoAuthorizationState::mint($tenant->id));

        $this->mock(SsoAuthService::class, function ($mock) use ($user, $link) {
            $mock->shouldReceive('completeCallback')
                ->once()
                ->andReturn(SsoIdentityResolutionResult::success($user, $link));
        });

        $this->withoutMiddleware([
            ...$this->pathSsoSessionMatrixMiddlewareToSkip(),
            // Exercise controller D12 deny (not BK-073 conflict abort).
            \App\Http\Middleware\RejectTenancyContextConflict::class,
            \Modules\Identity\Http\Middleware\EnsureMfaVerified::class,
        ]);

        $this->actingAs($user, 'web');
        $this->withSession(['tenant_id' => 'wrong-tenant-binding']);

        $this->get('/auth/sso/callback?code=abc&state='.urlencode($state))
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('email');

        $this->assertAuthenticatedAs($user, 'web');
        $this->assertSame('wrong-tenant-binding', session('tenant_id'));
    }

    #[Test]
    #[Group('path-profile-contract')]
    public function callback_same_user_missing_tenant_binding_denies_and_preserves_principal(): void
    {
        [$tenant, $user] = $this->createOrgTenantWithMember();
        $this->enableSsoForTenant($tenant);

        tenancy()->initialize($tenant);
        $link = TenantUserIdentity::query()->create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'issuer' => 'https://login.microsoftonline.com/tenant/v2.0',
            'subject' => 'sub-'.Str::uuid()->toString(),
        ]);
        tenancy()->end();

        $state = SsoAuthorizationState::encode(SsoAuthorizationState::mint($tenant->id));

        $this->mock(SsoAuthService::class, function ($mock) use ($user, $link) {
            $mock->shouldReceive('completeCallback')
                ->once()
                ->andReturn(SsoIdentityResolutionResult::success($user, $link));
        });

        $this->withoutMiddleware([
            ...$this->pathSsoSessionMatrixMiddlewareToSkip(),
            \Modules\Identity\Http\Middleware\EnsureMfaVerified::class,
        ]);

        $this->actingAs($user, 'web');
        $this->withSession(['probe' => 'no-tenant-binding']);

        $this->get('/auth/sso/callback?code=abc&state='.urlencode($state))
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('email');

        $this->assertAuthenticatedAs($user, 'web');
        $this->assertNull(session('tenant_id'));
    }

    #[Test]
    #[Group('path-profile-contract')]
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
    #[Group('path-profile-contract')]
    public function callback_response_does_not_expose_tokens(): void
    {
        [$tenant] = $this->createOrgTenantWithMember();
        $this->enableSsoForTenant($tenant);

        $state = SsoAuthorizationState::encode(SsoAuthorizationState::mint($tenant->id));
        $secretToken = 'super-secret-access-token-value';

        $this->mock(SsoAuthService::class, function ($mock) {
            $mock->shouldReceive('completeCallback')
                ->once()
                ->andReturn(SsoIdentityResolutionResult::failed(SsoIdentityResolutionResult::REASON_IDENTITY_NOT_PROVISIONED));
        });

        $response = $this->get('/auth/sso/callback?code='.$secretToken.'&state='.urlencode($state));

        $response->assertRedirect(route('login'));
        $this->assertStringNotContainsString($secretToken, $response->getContent() ?? '');
    }

    #[Test]
    #[Group('path-profile-contract')]
    public function callback_resolution_failure_never_logs_in_target_user(): void
    {
        [$tenant, $user] = $this->createOrgTenantWithMember();
        $this->enableSsoForTenant($tenant);

        $state = SsoAuthorizationState::encode(SsoAuthorizationState::mint($tenant->id));

        $this->mock(SsoAuthService::class, function ($mock) {
            $mock->shouldReceive('completeCallback')
                ->once()
                ->andReturn(SsoIdentityResolutionResult::failed(
                    SsoIdentityResolutionResult::REASON_IDENTITY_NOT_PROVISIONED
                ));
        });

        $this->get('/auth/sso/callback?code=abc&state='.urlencode($state))
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('email');

        $this->assertGuest('web');
        $this->assertNotEquals($user->id, auth('web')->id());
    }

    #[Test]
    #[Group('path-profile-contract')]
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
                ->andReturn(SsoIdentityResolutionResult::success($user, new TenantUserIdentity));
        });

        $this->withoutMiddleware(\Modules\Identity\Http\Middleware\EnsureMfaVerified::class);

        $this->get('/auth/sso/callback?code=abc&state='.urlencode($state))
            ->assertRedirect($this->tenantDashboardRedirectUri($tenant));

        $this->assertSame($connection, config('session.connection'));
        $this->assertGreaterThan(0, DB::connection($connection)->table('sessions')->count());
    }

    #[Test]
    #[Group('path-profile-contract')]
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
            'scopes' => ['openid', 'profile', 'email'],
        ]);
        tenancy()->end();

        $this->get('/t/'.$tenant->id.'/auth/sso/redirect')
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('email');
    }

    #[Test]
    #[Group('path-profile-contract')]
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
    public function path_controller_owns_path_federated_login_not_sso_auth_service(): void
    {
        $controller = file_get_contents(base_path('Modules/Identity/app/Http/Controllers/SsoAuthController.php'));
        $service = file_get_contents(base_path('Modules/Identity/app/Services/SsoAuthService.php'));

        $this->assertStringContainsString("Auth::guard('web')->login", $controller);
        $this->assertStringNotContainsString('Auth::login(', $service);
        $this->assertStringNotContainsString("Auth::guard('web')->login", $service);
    }
}
