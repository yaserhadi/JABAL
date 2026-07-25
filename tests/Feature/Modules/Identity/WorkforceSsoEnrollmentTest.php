<?php

namespace Tests\Feature\Modules\Identity;

use App\Models\User;
use Facile\OpenIDClient\Token\TokenSetInterface;
use Illuminate\Support\Str;
use Mockery;
use Modules\Identity\Models\Membership;
use Modules\Identity\Models\SsoAuthenticationTransaction;
use Modules\Identity\Models\SsoEnrollmentContinuation;
use Modules\Identity\Models\SsoTenantHandoff;
use Modules\Identity\Models\TenantUserIdentity;
use Modules\Identity\Models\WorkforceSsoEnrollmentInvitation;
use Modules\Identity\Services\AuthenticationTransactionService;
use Modules\Identity\Services\SsoAuthService;
use Modules\Identity\Services\SsoConfigService;
use Modules\Identity\Services\WorkforceSsoEnrollmentAssociationService;
use Modules\Identity\Services\WorkforceSsoEnrollmentInvitationService;
use Modules\Identity\Support\Sso\SsoAuthorizationResponseParser;
use Modules\Identity\Support\Sso\SsoBrowserBindingCookieFactory;
use Modules\Identity\Support\Sso\SsoIdentityResolver;
use Modules\Identity\Support\Sso\SsoSecretCrypto;
use Modules\Identity\Support\Sso\SsoValidatedClaims;
use Modules\Tenancy\Models\Tenant;
use Modules\Tenancy\Services\TenantDomainProvisioner;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\GrantsSsoEntitlement;
use Tests\Support\InteractsWithTenantAddressingProfile;
use Tests\TestCase;

/** BK-099 Scenario B — Workforce SSO enrollment (Host). */
class WorkforceSsoEnrollmentTest extends TestCase
{
    use GrantsSsoEntitlement;
    use InteractsWithTenantAddressingProfile;

    protected string $issuer = 'https://idp.example.com';

    protected function setUp(): void
    {
        $this->forceAddressingEnv('host');
        parent::setUp();
        config(['identity.sso.host_response_mode' => SsoAuthorizationResponseParser::MODE_QUERY]);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
        $this->restoreAddressingEnv();
    }

    /**
     * @return array{
     *   tenant: Tenant,
     *   admin: User,
     *   target: User,
     *   other: User,
     *   membership: Membership,
     *   host: string,
     *   invitation: WorkforceSsoEnrollmentInvitation,
     *   plainToken: string,
     *   versionId: string,
     * }
     */
    protected function prepareEnrollmentFixture(): array
    {
        $tenant = Tenant::factory()->create([
            'slug' => 'enr-'.Str::lower(Str::random(8)),
            'status' => 'active',
        ]);
        $this->grantSsoAvailable($tenant);
        app(TenantDomainProvisioner::class)->ensurePlatformSubdomain($tenant);
        $host = $tenant->slug.'.jabal.test';

        tenancy()->initialize($tenant);

        $admin = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Admin',
            'email' => 'admin-'.uniqid().'@example.com',
            'password' => 'password',
        ]);
        Membership::create([
            'tenant_id' => $tenant->id,
            'user_id' => $admin->id,
            'membership_type' => 'admin',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        $target = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Target',
            'email' => 'target-'.uniqid().'@example.com',
            'password' => 'password',
        ]);
        $membership = Membership::create([
            'tenant_id' => $tenant->id,
            'user_id' => $target->id,
            'membership_type' => 'member',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        $other = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Other',
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

        app(SsoConfigService::class)->update($tenant, [
            'enabled' => true,
            'issuer_url' => $this->issuer,
            'client_id' => 'client-id',
            'client_secret' => 'client-secret',
            'redirect_uri' => 'https://auth.jabal.test/auth/enterprise-sso/callback',
        ]);
        $versionId = app(SsoConfigService::class)->getActiveVersionId($tenant);
        $this->assertNotNull($versionId);

        $created = app(WorkforceSsoEnrollmentInvitationService::class)->createInvitation(
            $tenant,
            $admin,
            $target,
            $membership,
            'notify-only-'.uniqid().'@delivery.example',
            $host,
        );

        tenancy()->end();

        return [
            'tenant' => $tenant->fresh(),
            'admin' => $admin,
            'target' => $target,
            'other' => $other,
            'membership' => $membership,
            'host' => $host,
            'invitation' => $created['invitation'],
            'plainToken' => $created['plainToken'],
            'versionId' => $versionId,
        ];
    }

    /**
     * @return array{reference: string, continuationSecret: string, transaction: SsoAuthenticationTransaction}
     */
    protected function issueEnrollmentContinuationFor(array $fixture, string $subject = 'sub-enroll-1'): array
    {
        $created = app(AuthenticationTransactionService::class)->create([
            'tenant_id' => (string) $fixture['tenant']->id,
            'destination_host' => $fixture['host'],
            'addressing_profile' => 'host',
            'post_login_path' => '/auth/enterprise-sso/enrollment/complete',
            'idp_configuration_version_id' => $fixture['versionId'],
            'expected_issuer' => $this->issuer,
            'purpose' => SsoAuthenticationTransaction::PURPOSE_WORKFORCE_SSO_ENROLLMENT,
            'enrollment_invitation_id' => (string) $fixture['invitation']->id,
            'intended_user_id' => (string) $fixture['target']->id,
        ]);

        $authBinding = SsoSecretCrypto::opaqueToken(SsoSecretCrypto::BINDING_SECRET_BYTES);
        app(AuthenticationTransactionService::class)->attachAuthBinding($created['transaction'], $authBinding);

        $reserved = app(AuthenticationTransactionService::class)->reserveCallback($created['transaction']->id);
        $this->assertNotNull($reserved);

        $issued = app(AuthenticationTransactionService::class)->issueEnrollmentContinuation($reserved, [
            'issuer' => $this->issuer,
            'subject' => $subject,
            'invitation_id' => (string) $fixture['invitation']->id,
            'intended_user_id' => (string) $fixture['target']->id,
        ]);

        return [
            'reference' => $issued['reference'],
            'continuationSecret' => $created['tenant_continuation_secret'],
            'transaction' => $reserved->fresh(),
        ];
    }

    #[Test]
    #[Group('host-profile-contract')]
    public function unauthenticated_open_redirects_to_login_with_resume_cookies(): void
    {
        $fixture = $this->prepareEnrollmentFixture();

        $response = $this->call(
            'GET',
            'https://'.$fixture['host'].'/security/sso/enrollment/invitations/'.$fixture['plainToken'],
            server: ['HTTP_HOST' => $fixture['host'], 'SERVER_NAME' => $fixture['host'], 'HTTPS' => 'on']
        );

        $response->assertRedirect();
        $this->assertStringContainsString('/login', (string) $response->headers->get('Location'));
        $cookies = $response->headers->getCookies();
        $names = array_map(fn ($c) => $c->getName(), $cookies);
        $this->assertContains(SsoBrowserBindingCookieFactory::ENROLLMENT_LOGIN_RESUME, $names);
        $this->assertContains(SsoBrowserBindingCookieFactory::ENROLLMENT_BROWSER_BINDING, $names);
    }

    #[Test]
    #[Group('host-profile-contract')]
    public function login_as_target_can_start_enrollment(): void
    {
        $fixture = $this->prepareEnrollmentFixture();

        $this->actingAs($fixture['target']);
        tenancy()->initialize($fixture['tenant']);

        $response = $this->call(
            'GET',
            'https://'.$fixture['host'].'/security/sso/enrollment/invitations/'.$fixture['plainToken'],
            server: ['HTTP_HOST' => $fixture['host'], 'SERVER_NAME' => $fixture['host'], 'HTTPS' => 'on']
        );

        $response->assertOk();
        $response->assertInertia(
            fn ($page) => $page
                ->component('Security/SsoEnrollment/Ready')
                ->where('invitation_id', $fixture['invitation']->id)
        );

        $start = $this->call(
            'POST',
            'https://'.$fixture['host'].'/security/sso/enrollment/start',
            ['invitation_id' => $fixture['invitation']->id],
            server: ['HTTP_HOST' => $fixture['host'], 'SERVER_NAME' => $fixture['host'], 'HTTPS' => 'on']
        );

        $start->assertRedirect();
        $this->assertStringContainsString('auth.jabal.test/auth/enterprise-sso/initiate', (string) $start->headers->get('Location'));

        $txn = SsoAuthenticationTransaction::query()->latest('created_at')->first();
        $this->assertSame(SsoAuthenticationTransaction::PURPOSE_WORKFORCE_SSO_ENROLLMENT, $txn->purpose);
        $this->assertSame((string) $fixture['invitation']->id, (string) $txn->enrollment_invitation_id);
        $this->assertSame((string) $fixture['target']->id, (string) $txn->intended_user_id);

        tenancy()->end();
    }

    #[Test]
    #[Group('host-profile-contract')]
    public function session_as_other_user_is_denied(): void
    {
        $fixture = $this->prepareEnrollmentFixture();
        $this->actingAs($fixture['other']);

        $response = $this->call(
            'GET',
            'https://'.$fixture['host'].'/security/sso/enrollment/invitations/'.$fixture['plainToken'],
            server: ['HTTP_HOST' => $fixture['host'], 'SERVER_NAME' => $fixture['host'], 'HTTPS' => 'on']
        );

        $response->assertOk();
        $response->assertInertia(
            fn ($page) => $page
                ->component('Security/SsoEnrollment/Denied')
        );
    }

    #[Test]
    #[Group('host-profile-contract')]
    public function associate_creates_identity_for_target_only_without_session_or_membership_mutation(): void
    {
        $fixture = $this->prepareEnrollmentFixture();
        $issued = $this->issueEnrollmentContinuationFor($fixture);

        tenancy()->initialize($fixture['tenant']);
        $this->actingAs($fixture['target']);

        $sessionIdBefore = session()->getId();
        $membershipStatusBefore = Membership::query()->whereKey($fixture['membership']->id)->value('status');
        $membershipCountBefore = Membership::query()->count();

        $result = app(WorkforceSsoEnrollmentAssociationService::class)->associateFromWorkforceEnrollmentInvitation([
            'invitation' => $fixture['invitation']->fresh(),
            'authenticatedLocalActor' => $fixture['target'],
            'continuationReference' => $issued['reference'],
            'browserBinding' => $issued['continuationSecret'],
            'requestHost' => $fixture['host'],
        ]);

        $this->assertTrue($result['created']);
        $this->assertSame((string) $fixture['target']->id, (string) $result['identity']->user_id);
        $this->assertSame(1, TenantUserIdentity::query()->where('user_id', $fixture['target']->id)->count());
        $this->assertSame(0, TenantUserIdentity::query()->where('user_id', $fixture['other']->id)->count());
        $this->assertSame($sessionIdBefore, session()->getId());
        $this->assertSame($membershipStatusBefore, Membership::query()->whereKey($fixture['membership']->id)->value('status'));
        $this->assertSame($membershipCountBefore, Membership::query()->count());
        $this->assertNotNull($fixture['invitation']->fresh()->consumed_at);

        tenancy()->end();
    }

    #[Test]
    #[Group('host-profile-contract')]
    public function missing_idp_email_still_associates(): void
    {
        $fixture = $this->prepareEnrollmentFixture();
        $issued = $this->issueEnrollmentContinuationFor($fixture, 'sub-no-email');

        tenancy()->initialize($fixture['tenant']);

        $result = app(WorkforceSsoEnrollmentAssociationService::class)->associateFromWorkforceEnrollmentInvitation([
            'invitation' => $fixture['invitation']->fresh(),
            'authenticatedLocalActor' => $fixture['target'],
            'continuationReference' => $issued['reference'],
            'browserBinding' => $issued['continuationSecret'],
            'requestHost' => $fixture['host'],
            'email_at_link' => null,
        ]);

        $this->assertTrue($result['created']);
        $this->assertNull($result['identity']->email_at_link);

        tenancy()->end();
    }

    #[Test]
    #[Group('host-profile-contract')]
    public function delivery_email_is_not_used_to_pick_user(): void
    {
        $fixture = $this->prepareEnrollmentFixture();
        $issued = $this->issueEnrollmentContinuationFor($fixture, 'sub-delivery');

        tenancy()->initialize($fixture['tenant']);

        // Other user email equals delivery_email — must still fail actor check (target required).
        $fixture['other']->forceFill(['email' => $fixture['invitation']->delivery_email])->save();

        $this->expectException(\Modules\Identity\Exceptions\SsoSecurityException::class);

        app(WorkforceSsoEnrollmentAssociationService::class)->associateFromWorkforceEnrollmentInvitation([
            'invitation' => $fixture['invitation']->fresh(),
            'authenticatedLocalActor' => $fixture['other'],
            'continuationReference' => $issued['reference'],
            'browserBinding' => $issued['continuationSecret'],
            'requestHost' => $fixture['host'],
        ]);
    }

    #[Test]
    #[Group('host-profile-contract')]
    public function expired_cancelled_consumed_invitations_fail(): void
    {
        $fixture = $this->prepareEnrollmentFixture();
        tenancy()->initialize($fixture['tenant']);

        $expired = $fixture['invitation']->fresh();
        $expired->forceFill(['expires_at' => now()->subMinute()])->save();
        $this->assertNull(
            app(WorkforceSsoEnrollmentInvitationService::class)->findValidByToken(
                $fixture['tenant'],
                $fixture['plainToken'],
                $fixture['host'],
            )
        );

        $fixture2 = $this->prepareEnrollmentFixture();
        tenancy()->initialize($fixture2['tenant']);
        app(WorkforceSsoEnrollmentInvitationService::class)->cancelInvitation(
            $fixture2['tenant'],
            $fixture2['invitation'],
            $fixture2['admin'],
        );
        $this->assertNull(
            app(WorkforceSsoEnrollmentInvitationService::class)->findValidByToken(
                $fixture2['tenant'],
                $fixture2['plainToken'],
                $fixture2['host'],
            )
        );

        $fixture3 = $this->prepareEnrollmentFixture();
        $issued = $this->issueEnrollmentContinuationFor($fixture3, 'sub-consume');
        tenancy()->initialize($fixture3['tenant']);
        app(WorkforceSsoEnrollmentAssociationService::class)->associateFromWorkforceEnrollmentInvitation([
            'invitation' => $fixture3['invitation']->fresh(),
            'authenticatedLocalActor' => $fixture3['target'],
            'continuationReference' => $issued['reference'],
            'browserBinding' => $issued['continuationSecret'],
            'requestHost' => $fixture3['host'],
        ]);
        $this->assertNull(
            app(WorkforceSsoEnrollmentInvitationService::class)->findValidByToken(
                $fixture3['tenant'],
                $fixture3['plainToken'],
                $fixture3['host'],
            )
        );

        tenancy()->end();
    }

    #[Test]
    #[Group('host-profile-contract')]
    public function concurrent_associate_creates_one_row(): void
    {
        $fixture = $this->prepareEnrollmentFixture();
        $issued = $this->issueEnrollmentContinuationFor($fixture, 'sub-concurrent');

        tenancy()->initialize($fixture['tenant']);

        $svc = app(WorkforceSsoEnrollmentAssociationService::class);
        $first = $svc->associateFromWorkforceEnrollmentInvitation([
            'invitation' => $fixture['invitation']->fresh(),
            'authenticatedLocalActor' => $fixture['target'],
            'continuationReference' => $issued['reference'],
            'browserBinding' => $issued['continuationSecret'],
            'requestHost' => $fixture['host'],
        ]);
        $this->assertTrue($first['created']);

        try {
            $svc->associateFromWorkforceEnrollmentInvitation([
                'invitation' => $fixture['invitation']->fresh(),
                'authenticatedLocalActor' => $fixture['target'],
                'continuationReference' => $issued['reference'],
                'browserBinding' => $issued['continuationSecret'],
                'requestHost' => $fixture['host'],
            ]);
            $this->fail('Second associate should fail after continuation/invitation consume.');
        } catch (\Modules\Identity\Exceptions\SsoSecurityException) {
            // expected
        }

        $this->assertSame(1, TenantUserIdentity::query()
            ->where('issuer', $this->issuer)
            ->where('subject', 'sub-concurrent')
            ->count());

        tenancy()->end();
    }

    #[Test]
    #[Group('host-profile-contract')]
    public function host_callback_enrollment_purpose_does_not_create_handoff_or_identity(): void
    {
        $fixture = $this->prepareEnrollmentFixture();

        $created = app(AuthenticationTransactionService::class)->create([
            'tenant_id' => (string) $fixture['tenant']->id,
            'destination_host' => $fixture['host'],
            'addressing_profile' => 'host',
            'post_login_path' => '/auth/enterprise-sso/enrollment/complete',
            'idp_configuration_version_id' => $fixture['versionId'],
            'expected_issuer' => $this->issuer,
            'purpose' => SsoAuthenticationTransaction::PURPOSE_WORKFORCE_SSO_ENROLLMENT,
            'enrollment_invitation_id' => (string) $fixture['invitation']->id,
            'intended_user_id' => (string) $fixture['target']->id,
        ]);
        $authBinding = SsoSecretCrypto::opaqueToken(SsoSecretCrypto::BINDING_SECRET_BYTES);
        app(AuthenticationTransactionService::class)->attachAuthBinding($created['transaction'], $authBinding);

        $tokenSet = Mockery::mock(TokenSetInterface::class);
        $tokenSet->shouldReceive('claims')->andReturn([
            'iss' => $this->issuer,
            'sub' => 'sub-callback-enroll',
        ]);
        $mock = Mockery::mock(app(SsoAuthService::class))->makePartial();
        $mock->shouldReceive('exchangeHostAuthorizationCode')->once()->andReturn($tokenSet);
        $mock->shouldReceive('extractValidatedClaims')->once()->andReturn(
            new SsoValidatedClaims($this->issuer, 'sub-callback-enroll', null, null)
        );
        $this->app->instance(SsoAuthService::class, $mock);

        $response = $this->call(
            'GET',
            'https://auth.jabal.test/auth/enterprise-sso/callback?code=one-time-code&state='.rawurlencode($created['state']),
            cookies: [SsoBrowserBindingCookieFactory::AUTH_BINDING => $authBinding],
            server: ['HTTP_HOST' => 'auth.jabal.test', 'SERVER_NAME' => 'auth.jabal.test', 'HTTPS' => 'on']
        );

        $response->assertRedirect();
        $location = (string) $response->headers->get('Location');
        $this->assertStringContainsString('/auth/enterprise-sso/enrollment/complete?c=', $location);
        $this->assertStringNotContainsString('/auth/enterprise-sso/handoff', $location);

        $this->assertSame(0, SsoTenantHandoff::query()->count());
        $this->assertSame(1, SsoEnrollmentContinuation::query()->count());
        $this->assertGuest('web');

        tenancy()->initialize($fixture['tenant']);
        $this->assertSame(0, TenantUserIdentity::query()->count());
        tenancy()->end();
    }

    #[Test]
    #[Group('host-profile-contract')]
    public function ordinary_login_remains_existing_link_only(): void
    {
        $fixture = $this->prepareEnrollmentFixture();

        tenancy()->initialize($fixture['tenant']);
        $result = app(SsoIdentityResolver::class)->resolveExistingLinkOnly(
            $fixture['tenant'],
            new SsoValidatedClaims($this->issuer, 'never-linked-sub', 'anyone@example.com', true),
            $this->issuer,
        );
        $this->assertFalse($result->succeeded());
        $this->assertSame(0, TenantUserIdentity::query()->count());
        tenancy()->end();
    }
}
