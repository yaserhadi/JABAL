<?php

namespace Tests\Feature\Modules\Identity;

use App\Models\User;
use Illuminate\Support\Str;
use Modules\Billing\Models\Entitlement;
use Modules\Billing\Models\Plan;
use Modules\Billing\Models\Subscription;
use Modules\Identity\Models\Membership;
use Modules\Identity\Models\SsoTenantHandoff;
use Modules\Identity\Models\TenantUserIdentity;
use Modules\Identity\Models\UserSession;
use Modules\Identity\Services\AuthenticationTransactionService;
use Modules\Identity\Services\MfaService;
use Modules\Identity\Services\SecurityPolicyService;
use Modules\Identity\Services\SessionRegistryService;
use Modules\Identity\Services\SsoConfigService;
use Modules\Identity\Support\Sso\SsoBrowserBindingCookieFactory;
use Modules\Identity\Support\Sso\SsoMfaContinuation;
use Modules\Identity\Support\Sso\SsoSecretCrypto;
use Modules\Tenancy\Models\Tenant;
use Modules\Tenancy\Services\TenantDomainProvisioner;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PragmaRX\Google2FA\Google2FA;
use Tests\Concerns\GrantsSsoEntitlement;
use Tests\Support\InteractsWithTenantAddressingProfile;
use Tests\TestCase;

/** BK-082 Workstream 5 — Tenant Host Handoff consume + session issuance. */
class HostEnterpriseSsoHandoffTest extends TestCase
{
    use GrantsSsoEntitlement;
    use InteractsWithTenantAddressingProfile;

    protected function setUp(): void
    {
        $this->forceAddressingEnv('host');
        parent::setUp();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->restoreAddressingEnv();
    }

    /**
     * @return array{
     *   tenant: Tenant,
     *   user: User,
     *   link: TenantUserIdentity,
     *   handoffReference: string,
     *   continuation: string,
     *   host: string
     * }
     */
    protected function prepareIssuedHandoff(bool $mfaRequired = false, ?array $assurance = null): array
    {
        $tenant = Tenant::factory()->create([
            'slug' => 'ws5-'.Str::lower(Str::random(8)),
            'status' => 'active',
        ]);
        if ($mfaRequired) {
            $this->grantSsoAndMfaEntitlements($tenant, required: true);
        } else {
            $this->grantSsoAvailable($tenant);
        }
        app(TenantDomainProvisioner::class)->ensurePlatformSubdomain($tenant);

        tenancy()->initialize($tenant);
        $user = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'WS5 User',
            'email' => 'ws5-'.uniqid().'@example.com',
            'password' => 'password',
        ]);
        Membership::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'membership_type' => 'member',
            'status' => 'active',
            'joined_at' => now(),
        ]);
        app(SsoConfigService::class)->update($tenant, [
            'enabled' => true,
            'issuer_url' => 'https://idp.example.com',
            'client_id' => 'client-id',
            'client_secret' => 'client-secret',
            'redirect_uri' => 'https://auth.jabal.test/auth/enterprise-sso/callback',
            'approved_email_domains' => ['example.com'],
        ]);
        if ($mfaRequired) {
            $mfa = app(MfaService::class);
            $setup = $mfa->beginEnrollment($user);
            $code = (new Google2FA)->getCurrentOtp($setup['secret']);
            $mfa->confirmEnrollment($user, $code);
            session()->forget('mfa_verified_at');
        }
        $link = TenantUserIdentity::query()->create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'issuer' => 'https://idp.example.com',
            'subject' => 'subject-ws5',
            'email_at_link' => $user->email,
        ]);
        $versionId = app(SsoConfigService::class)->getActiveVersionId($tenant);
        tenancy()->end();

        $host = $tenant->slug.'.jabal.test';
        $created = app(AuthenticationTransactionService::class)->create([
            'tenant_id' => (string) $tenant->id,
            'destination_host' => $host,
            'addressing_profile' => 'host',
            'post_login_path' => '/dashboard',
            'idp_configuration_version_id' => $versionId,
            'expected_issuer' => 'https://idp.example.com',
        ]);
        $authBinding = SsoSecretCrypto::opaqueToken(SsoSecretCrypto::BINDING_SECRET_BYTES);
        $txn = app(AuthenticationTransactionService::class)->attachAuthBinding($created['transaction'], $authBinding);
        $reserved = app(AuthenticationTransactionService::class)->reserveCallback($txn->id);
        $this->assertNotNull($reserved);
        $issued = app(AuthenticationTransactionService::class)->issueHandoff($reserved, [
            'user_id' => (string) $user->id,
            'identity_link_id' => (string) $link->id,
            'assurance_evidence' => $assurance,
        ]);

        return [
            'tenant' => $tenant->fresh(),
            'user' => $user,
            'link' => $link,
            'handoffReference' => $issued['reference'],
            'continuation' => $created['tenant_continuation_secret'],
            'host' => $host,
        ];
    }

    protected function grantSsoAndMfaEntitlements(Tenant $tenant, bool $required = false): void
    {
        $plan = Plan::query()->firstOrCreate(
            ['code' => 'sso-mfa-ws5'],
            ['name' => 'SSO MFA WS5', 'is_active' => true]
        );

        foreach (['sso_available', 'mfa_available'] as $code) {
            Entitlement::query()->firstOrCreate(
                ['plan_id' => $plan->id, 'code' => $code],
                ['name' => ucwords(str_replace('_', ' ', $code)), 'is_active' => true]
            );
        }

        if ($required) {
            Entitlement::query()->firstOrCreate(
                ['plan_id' => $plan->id, 'code' => 'mfa_required'],
                ['name' => 'MFA Required', 'is_active' => true]
            );
        }

        Subscription::query()->updateOrCreate(
            ['tenant_id' => $tenant->id, 'status' => 'active'],
            [
                'id' => Str::uuid()->toString(),
                'plan_id' => $plan->id,
                'starts_at' => now(),
            ]
        );

        if ($required) {
            app(SecurityPolicyService::class)->update($tenant, ['mfa_required' => true]);
        }
    }

    protected function handoffCall(array $fixture, array $extraCookies = [])
    {
        return $this->call(
            'GET',
            'https://'.$fixture['host'].'/auth/enterprise-sso/handoff?h='.rawurlencode($fixture['handoffReference']),
            cookies: array_merge(
                [SsoBrowserBindingCookieFactory::TENANT_CONTINUATION => $fixture['continuation']],
                $extraCookies
            ),
            server: [
                'HTTP_HOST' => $fixture['host'],
                'SERVER_NAME' => $fixture['host'],
                'HTTPS' => 'on',
            ]
        );
    }

    #[Test]
    #[Group('host-profile-contract')]
    public function handoff_issues_full_session_and_usersession_without_h_param(): void
    {
        $fixture = $this->prepareIssuedHandoff();
        $this->assignDashboardViewToUser($fixture['user'], $fixture['tenant']);

        $response = $this->handoffCall($fixture);
        $response->assertRedirect();
        $location = (string) $response->headers->get('Location');
        $this->assertStringNotContainsString('h=', $location);
        $this->assertStringContainsString('/dashboard', $location);
        $this->assertAuthenticatedAs($fixture['user'], 'web');

        tenancy()->initialize($fixture['tenant']);
        $this->assertSame(1, UserSession::query()->where('user_id', $fixture['user']->id)->count());
        $this->assertSame(
            \Modules\Identity\Support\Sso\SsoIdentityLifecycle::STATUS_READY,
            $fixture['link']->fresh()->verification_status
        );
        $this->assertNotNull($fixture['link']->fresh()->ready_at);
        $this->assertSame((string) $fixture['user']->id, (string) $fixture['link']->fresh()->user_id);
        tenancy()->end();

        $this->assertSame(SsoTenantHandoff::STATUS_CONSUMED, SsoTenantHandoff::query()->first()->status);
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    #[Test]
    #[Group('host-profile-contract')]
    public function missing_continuation_or_invalid_handoff_fails_closed(): void
    {
        $fixture = $this->prepareIssuedHandoff();

        $this->call(
            'GET',
            'https://'.$fixture['host'].'/auth/enterprise-sso/handoff?h='.rawurlencode($fixture['handoffReference']),
            server: ['HTTP_HOST' => $fixture['host'], 'SERVER_NAME' => $fixture['host'], 'HTTPS' => 'on']
        )->assertNotFound();

        $this->call(
            'GET',
            'https://'.$fixture['host'].'/auth/enterprise-sso/handoff?h=not-valid',
            cookies: [SsoBrowserBindingCookieFactory::TENANT_CONTINUATION => $fixture['continuation']],
            server: ['HTTP_HOST' => $fixture['host'], 'SERVER_NAME' => $fixture['host'], 'HTTPS' => 'on']
        )->assertNotFound();

        $this->assertGuest('web');
        $this->assertSame(SsoTenantHandoff::STATUS_ISSUED, SsoTenantHandoff::query()->first()->status);
    }

    #[Test]
    #[Group('host-profile-contract')]
    public function linked_not_ready_same_user_regenerates_and_marks_ready(): void
    {
        $fixture = $this->prepareIssuedHandoff();
        $this->assignDashboardViewToUser($fixture['user'], $fixture['tenant']);

        tenancy()->initialize($fixture['tenant']);
        $versionId = app(SsoConfigService::class)->getActiveVersionId($fixture['tenant']);
        app(\Modules\Identity\Support\Sso\SsoIdentityLifecycle::class)->markLinked(
            $fixture['link'],
            (string) $fixture['tenant']->id,
            $versionId,
        );
        $this->assertNull($fixture['link']->fresh()->ready_at);
        tenancy()->end();

        $this->actingAs($fixture['user'], 'web');
        $this->withSession(['tenant_id' => $fixture['tenant']->id, 'mfa_verified_at' => 'stale-enrollment-context']);
        $before = $this->app['session.store']->getId();

        $response = $this->handoffCall($fixture);
        $response->assertRedirect();
        $this->assertAuthenticatedAs($fixture['user'], 'web');
        $this->assertNotSame($before, $this->app['session.store']->getId());

        tenancy()->initialize($fixture['tenant']);
        $this->assertSame(
            \Modules\Identity\Support\Sso\SsoIdentityLifecycle::STATUS_READY,
            $fixture['link']->fresh()->verification_status
        );
        $this->assertNotNull($fixture['link']->fresh()->ready_at);
        $this->assertSame((string) $fixture['user']->id, (string) $fixture['link']->fresh()->user_id);
        $this->assertSame(1, UserSession::query()->where('user_id', $fixture['user']->id)->count());
        tenancy()->end();
    }

    #[Test]
    #[Group('host-profile-contract')]
    public function ordinary_same_user_session_is_not_silently_replaced_when_already_ready(): void
    {
        $fixture = $this->prepareIssuedHandoff();
        $this->assignDashboardViewToUser($fixture['user'], $fixture['tenant']);

        tenancy()->initialize($fixture['tenant']);
        $versionId = app(SsoConfigService::class)->getActiveVersionId($fixture['tenant']);
        app(\Modules\Identity\Support\Sso\SsoIdentityLifecycle::class)->markLinked(
            $fixture['link'],
            (string) $fixture['tenant']->id,
            $versionId,
        );
        app(\Modules\Identity\Support\Sso\SsoIdentityLifecycle::class)->markLoginVerifiedAndReady(
            $fixture['link']->fresh(),
            $fixture['user'],
            (string) $fixture['tenant']->id,
            (string) $versionId,
            'test_seed_ready',
        );
        tenancy()->end();

        $this->mock(SessionRegistryService::class, function ($mock) {
            $mock->shouldNotReceive('register');
        });

        $this->actingAs($fixture['user'], 'web');
        $this->withSession(['tenant_id' => $fixture['tenant']->id]);

        $versionId = app(SsoConfigService::class)->getActiveVersionId($fixture['tenant']);
        $created = app(AuthenticationTransactionService::class)->create([
            'tenant_id' => (string) $fixture['tenant']->id,
            'destination_host' => $fixture['host'],
            'addressing_profile' => 'host',
            'post_login_path' => '/dashboard',
            'idp_configuration_version_id' => $versionId,
        ]);
        $txn = app(AuthenticationTransactionService::class)->attachAuthBinding(
            $created['transaction'],
            SsoSecretCrypto::opaqueToken(SsoSecretCrypto::BINDING_SECRET_BYTES)
        );
        $reserved = app(AuthenticationTransactionService::class)->reserveCallback($txn->id);
        $issued = app(AuthenticationTransactionService::class)->issueHandoff($reserved, [
            'user_id' => (string) $fixture['user']->id,
            'identity_link_id' => (string) $fixture['link']->id,
        ]);

        $response2 = $this->call(
            'GET',
            'https://'.$fixture['host'].'/auth/enterprise-sso/handoff?h='.rawurlencode($issued['reference']),
            cookies: [SsoBrowserBindingCookieFactory::TENANT_CONTINUATION => $created['tenant_continuation_secret']],
            server: ['HTTP_HOST' => $fixture['host'], 'SERVER_NAME' => $fixture['host'], 'HTTPS' => 'on']
        );
        $response2->assertRedirect();
        $this->assertAuthenticatedAs($fixture['user'], 'web');
        $this->assertSame(SsoTenantHandoff::STATUS_CONSUMED, SsoTenantHandoff::query()->find(
            explode('.', $issued['reference'], 2)[0]
        )->status);

        tenancy()->initialize($fixture['tenant']);
        $this->assertSame(0, UserSession::query()->where('user_id', $fixture['user']->id)->count());
        tenancy()->end();
    }

    #[Test]
    #[Group('host-profile-contract')]
    public function different_tenant_handoff_while_authenticated_fails_closed_without_consume(): void
    {
        $fixtureA = $this->prepareIssuedHandoff();
        $this->assignDashboardViewToUser($fixtureA['user'], $fixtureA['tenant']);
        $this->actingAs($fixtureA['user'], 'web');
        $this->withSession(['tenant_id' => $fixtureA['tenant']->id]);

        $fixtureB = $this->prepareIssuedHandoff();
        $this->call(
            'GET',
            'https://'.$fixtureA['host'].'/auth/enterprise-sso/handoff?h='.rawurlencode($fixtureB['handoffReference']),
            cookies: [SsoBrowserBindingCookieFactory::TENANT_CONTINUATION => $fixtureB['continuation']],
            server: ['HTTP_HOST' => $fixtureA['host'], 'SERVER_NAME' => $fixtureA['host'], 'HTTPS' => 'on']
        )->assertNotFound();

        $this->assertSame(SsoTenantHandoff::STATUS_ISSUED, SsoTenantHandoff::query()->find(
            explode('.', $fixtureB['handoffReference'], 2)[0]
        )->status);
    }

    #[Test]
    #[Group('host-profile-contract')]
    public function different_user_same_tenant_while_authenticated_fails_closed_without_consume(): void
    {
        $fixture = $this->prepareIssuedHandoff();
        $this->assignDashboardViewToUser($fixture['user'], $fixture['tenant']);
        $this->actingAs($fixture['user'], 'web');
        $this->withSession(['tenant_id' => $fixture['tenant']->id]);

        tenancy()->initialize($fixture['tenant']);
        $other = User::create([
            'tenant_id' => $fixture['tenant']->id,
            'name' => 'Other User',
            'email' => 'ws5-other-'.uniqid().'@example.com',
            'password' => 'password',
        ]);
        Membership::create([
            'tenant_id' => $fixture['tenant']->id,
            'user_id' => $other->id,
            'membership_type' => 'member',
            'status' => 'active',
            'joined_at' => now(),
        ]);
        $otherLink = TenantUserIdentity::query()->create([
            'tenant_id' => $fixture['tenant']->id,
            'user_id' => $other->id,
            'issuer' => 'https://idp.example.com',
            'subject' => 'subject-ws5-other',
            'email_at_link' => $other->email,
        ]);
        $versionId = app(SsoConfigService::class)->getActiveVersionId($fixture['tenant']);
        tenancy()->end();

        $created = app(AuthenticationTransactionService::class)->create([
            'tenant_id' => (string) $fixture['tenant']->id,
            'destination_host' => $fixture['host'],
            'addressing_profile' => 'host',
            'post_login_path' => '/dashboard',
            'idp_configuration_version_id' => $versionId,
        ]);
        $txn = app(AuthenticationTransactionService::class)->attachAuthBinding(
            $created['transaction'],
            SsoSecretCrypto::opaqueToken(SsoSecretCrypto::BINDING_SECRET_BYTES)
        );
        $reserved = app(AuthenticationTransactionService::class)->reserveCallback($txn->id);
        $issued = app(AuthenticationTransactionService::class)->issueHandoff($reserved, [
            'user_id' => (string) $other->id,
            'identity_link_id' => (string) $otherLink->id,
        ]);

        $this->call(
            'GET',
            'https://'.$fixture['host'].'/auth/enterprise-sso/handoff?h='.rawurlencode($issued['reference']),
            cookies: [SsoBrowserBindingCookieFactory::TENANT_CONTINUATION => $created['tenant_continuation_secret']],
            server: ['HTTP_HOST' => $fixture['host'], 'SERVER_NAME' => $fixture['host'], 'HTTPS' => 'on']
        )->assertNotFound();

        $this->assertSame(SsoTenantHandoff::STATUS_ISSUED, SsoTenantHandoff::query()->find(
            explode('.', $issued['reference'], 2)[0]
        )->status);
        $this->assertAuthenticatedAs($fixture['user'], 'web');
    }

    #[Test]
    #[Group('host-profile-contract')]
    public function mfa_required_without_idp_assurance_defers_usersession(): void
    {
        $fixture = $this->prepareIssuedHandoff(mfaRequired: true, assurance: ['acr' => 'urn:example:aal1']);
        $this->assignDashboardViewToUser($fixture['user'], $fixture['tenant']);

        $response = $this->handoffCall($fixture);
        $response->assertRedirect();
        $this->assertStringContainsString('/security/mfa/challenge', (string) $response->headers->get('Location'));
        $this->assertAuthenticatedAs($fixture['user'], 'web');
        $this->assertNotNull(session(SsoMfaContinuation::SESSION_KEY));
        $this->assertTrue((bool) session(SsoMfaContinuation::DEFER_USER_SESSION_KEY));

        tenancy()->initialize($fixture['tenant']);
        $this->assertSame(0, UserSession::query()->where('user_id', $fixture['user']->id)->count());
        tenancy()->end();
    }

    #[Test]
    #[Group('host-profile-contract')]
    public function handoff_replay_fails_after_consume(): void
    {
        $fixture = $this->prepareIssuedHandoff();
        $this->assignDashboardViewToUser($fixture['user'], $fixture['tenant']);
        $this->handoffCall($fixture)->assertRedirect();

        $this->call(
            'GET',
            'https://'.$fixture['host'].'/auth/enterprise-sso/handoff?h='.rawurlencode($fixture['handoffReference']),
            cookies: [SsoBrowserBindingCookieFactory::TENANT_CONTINUATION => $fixture['continuation']],
            server: ['HTTP_HOST' => $fixture['host'], 'SERVER_NAME' => $fixture['host'], 'HTTPS' => 'on']
        )->assertNotFound();
    }

    #[Test]
    #[Group('host-profile-contract')]
    public function ws5_surfaces_do_not_call_attempt_first_link_or_auth_host_login_patterns(): void
    {
        $service = file_get_contents(base_path('Modules/Identity/app/Services/HostEnterpriseSsoHandoffService.php'));
        $controller = file_get_contents(base_path('Modules/Identity/app/Http/Controllers/EnterpriseSsoHandoffController.php'));
        foreach ([$service, $controller] as $source) {
            $this->assertStringNotContainsString('attemptFirstLink(', $source);
            $this->assertStringNotContainsString('exchangeHostAuthorizationCode', $source);
            $this->assertStringNotContainsString('LaravelSessionAuthSessionAdapter', $source);
        }
        $this->assertStringContainsString('Auth::guard(\'web\')->login', $service);
        $this->assertStringContainsString('sessionRegistry->register', $service);
    }

    #[Test]
    #[Group('path-profile-contract')]
    public function path_profile_does_not_expose_enterprise_sso_handoff_route(): void
    {
        $this->forceAddressingEnv('path');
        $this->refreshApplication();

        $this->get('/auth/enterprise-sso/handoff?h=anything')->assertNotFound();
    }
}
