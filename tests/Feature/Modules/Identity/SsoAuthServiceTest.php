<?php

namespace Tests\Feature\Modules\Identity;

use Modules\Identity\Services\SsoAuthService;
use Modules\Identity\Services\SsoConfigService;
use Modules\Identity\Support\Sso\LaravelSessionAuthSessionAdapter;
use Modules\Identity\Support\Sso\SsoIdentityResolutionResult;
use Modules\Identity\Support\Sso\SsoValidatedClaims;
use Modules\Tenancy\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\GrantsSsoEntitlement;
use Tests\TestCase;

class SsoAuthServiceTest extends TestCase
{
    use GrantsSsoEntitlement;

    #[Test]
    public function service_source_does_not_call_auth_login(): void
    {
        $source = file_get_contents(base_path('Modules/Identity/app/Services/SsoAuthService.php'));

        $this->assertIsString($source);
        $this->assertStringNotContainsString('Auth::login(', $source);
        $this->assertStringNotContainsString('Auth::guard(', $source);
    }

    #[Test]
    public function prepare_authorization_session_stores_pkce_and_oidc_state_in_session(): void
    {
        $tenant = Tenant::factory()->create();
        $this->grantSsoAvailable($tenant);

        tenancy()->initialize($tenant);
        app(SsoConfigService::class)->update($tenant, [
            'enabled' => true,
            'issuer_url' => 'https://login.microsoftonline.com/tenant/v2.0',
            'client_id' => 'client-id',
            'client_secret' => 'secret',
        ]);
        tenancy()->end();

        $session = $this->app['session.store'];
        $authService = app(SsoAuthService::class);

        $prepared = $authService->prepareAuthorizationSession($tenant);

        $this->assertNotEmpty($prepared['code_challenge']);
        $this->assertSame('S256', $prepared['code_challenge_method']);
        $this->assertNotNull($prepared['session']->getState());
        $this->assertNotNull($prepared['session']->getCodeVerifier());
        $this->assertNotNull($session->get(LaravelSessionAuthSessionAdapter::sessionKey($tenant->id)));
    }

    #[Test]
    public function complete_callback_rejects_tenant_mismatch_between_state_and_tenant_context(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $this->grantSsoAvailable($tenantA);
        $this->grantSsoAvailable($tenantB);

        foreach ([$tenantA, $tenantB] as $tenant) {
            tenancy()->initialize($tenant);
            app(SsoConfigService::class)->update($tenant, [
                'enabled' => true,
                'issuer_url' => 'https://login.microsoftonline.com/tenant/v2.0',
                'client_id' => 'client-id',
                'client_secret' => 'secret',
            ]);
            tenancy()->end();
        }

        $authService = app(SsoAuthService::class);
        $prepared = $authService->prepareAuthorizationSession($tenantA);
        $state = $prepared['state'];

        $result = $authService->completeCallback($tenantB, [
            'code' => 'auth-code',
            'state' => $state,
        ]);

        $this->assertFalse($result->succeeded());
        $this->assertSame('tenant_mismatch', $result->failureReason);
    }

    #[Test]
    public function complete_callback_rejects_missing_authorization_session(): void
    {
        $tenant = Tenant::factory()->create();
        $this->grantSsoAvailable($tenant);

        tenancy()->initialize($tenant);
        app(SsoConfigService::class)->update($tenant, [
            'enabled' => true,
            'issuer_url' => 'https://login.microsoftonline.com/tenant/v2.0',
            'client_id' => 'client-id',
            'client_secret' => 'secret',
        ]);
        tenancy()->end();

        $state = \Modules\Identity\Support\Sso\SsoAuthorizationState::encode(
            \Modules\Identity\Support\Sso\SsoAuthorizationState::mint($tenant->id)
        );

        $result = app(SsoAuthService::class)->completeCallback($tenant, [
            'code' => 'auth-code',
            'state' => $state,
        ]);

        $this->assertFalse($result->succeeded());
        $this->assertSame('invalid_state', $result->failureReason);
    }

    #[Test]
    public function complete_callback_rejects_state_session_mismatch(): void
    {
        $tenant = Tenant::factory()->create();
        $this->grantSsoAvailable($tenant);

        tenancy()->initialize($tenant);
        app(SsoConfigService::class)->update($tenant, [
            'enabled' => true,
            'issuer_url' => 'https://login.microsoftonline.com/tenant/v2.0',
            'client_id' => 'client-id',
            'client_secret' => 'secret',
        ]);
        tenancy()->end();

        $authService = app(SsoAuthService::class);
        $authService->prepareAuthorizationSession($tenant);

        $otherState = \Modules\Identity\Support\Sso\SsoAuthorizationState::encode(
            \Modules\Identity\Support\Sso\SsoAuthorizationState::mint($tenant->id)
        );

        $result = $authService->completeCallback($tenant, [
            'code' => 'auth-code',
            'state' => $otherState,
        ]);

        $this->assertFalse($result->succeeded());
        $this->assertSame('invalid_state', $result->failureReason);
    }

    #[Test]
    public function resolve_identity_rejects_configured_issuer_mismatch(): void
    {
        $tenant = Tenant::factory()->create();
        $this->grantSsoAvailable($tenant);

        tenancy()->initialize($tenant);
        app(SsoConfigService::class)->update($tenant, [
            'enabled' => true,
            'issuer_url' => 'https://example.com',
            'client_id' => 'client-id',
            'client_secret' => 'secret',
        ]);
        tenancy()->end();

        $result = app(SsoAuthService::class)->resolveIdentity(
            $tenant,
            new SsoValidatedClaims('https://other-idp.example.com', 'sub-1', 'user@example.com', true),
        );

        $this->assertFalse($result->succeeded());
        $this->assertSame(SsoIdentityResolutionResult::REASON_ISSUER_MISMATCH, $result->failureReason);
    }

    #[Test]
    public function complete_callback_returns_protocol_error_on_idp_failure(): void
    {
        $tenant = Tenant::factory()->create();
        $this->grantSsoAvailable($tenant);

        tenancy()->initialize($tenant);
        app(SsoConfigService::class)->update($tenant, [
            'enabled' => true,
            'issuer_url' => 'https://login.microsoftonline.com/tenant/v2.0',
            'client_id' => 'client-id',
            'client_secret' => 'secret',
        ]);
        tenancy()->end();

        $authService = app(SsoAuthService::class);
        $prepared = $authService->prepareAuthorizationSession($tenant);

        $result = $authService->completeCallback($tenant, [
            'code' => 'invalid-auth-code',
            'state' => $prepared['state'],
        ]);

        $this->assertFalse($result->succeeded());
        $this->assertSame('protocol_error', $result->failureReason);
    }
}
