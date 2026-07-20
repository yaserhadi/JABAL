<?php

namespace Tests\Feature\Modules\Identity;

use App\Models\User;
use App\Support\Contracts\Audit\AuditLoggerInterface;
use Facile\OpenIDClient\Token\TokenSetInterface;
use Illuminate\Support\Str;
use Mockery;
use Modules\Identity\Models\Membership;
use Modules\Identity\Models\SsoAuthenticationTransaction;
use Modules\Identity\Models\SsoTenantHandoff;
use Modules\Identity\Models\TenantUserIdentity;
use Modules\Identity\Services\AuthenticationTransactionService;
use Modules\Identity\Services\SsoAuthService;
use Modules\Identity\Services\SsoConfigService;
use Modules\Identity\Support\Sso\SsoBrowserBindingCookieFactory;
use Modules\Identity\Support\Sso\SsoIdentityResolutionResult;
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

/**
 * BK-082 Workstream 6 — Host D10 / C1 isolation / first-access boundaries (T31–T35).
 */
class HostEnterpriseSsoD10LinkingTest extends TestCase
{
    use GrantsSsoEntitlement;
    use InteractsWithTenantAddressingProfile;

    protected string $issuer = 'https://idp.example.com';

    protected function setUp(): void
    {
        $this->forceAddressingEnv('host');
        parent::setUp();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
        $this->restoreAddressingEnv();
    }

    /**
     * @return array{0: Tenant, 1: User}
     */
    protected function createOrgMember(string $email): array
    {
        $tenant = Tenant::factory()->create([
            'slug' => 'ws6-'.Str::lower(Str::random(8)),
            'status' => 'active',
        ]);
        $this->grantSsoAvailable($tenant);

        tenancy()->initialize($tenant);
        $user = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'WS6 Member',
            'email' => $email,
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

    /**
     * @return array{tenant: Tenant, user: User, link: TenantUserIdentity, created: array<string, mixed>, authBinding: string}
     */
    protected function prepareAwaitingCallbackWithLink(): array
    {
        [$tenant, $user] = $this->createOrgMember('ws6-'.uniqid().'@example.com');
        app(TenantDomainProvisioner::class)->ensurePlatformSubdomain($tenant);

        tenancy()->initialize($tenant);
        app(SsoConfigService::class)->update($tenant, [
            'enabled' => true,
            'issuer_url' => $this->issuer,
            'client_id' => 'client-id',
            'client_secret' => 'client-secret',
            'redirect_uri' => 'https://auth.jabal.test/auth/enterprise-sso/callback',
        ]);
        $link = TenantUserIdentity::query()->create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'issuer' => $this->issuer,
            'subject' => 'subject-ws6',
            'email_at_link' => $user->email,
        ]);
        $versionId = app(SsoConfigService::class)->getActiveVersionId($tenant);
        tenancy()->end();

        $created = app(AuthenticationTransactionService::class)->create([
            'tenant_id' => (string) $tenant->id,
            'destination_host' => $tenant->slug.'.jabal.test',
            'addressing_profile' => 'host',
            'post_login_path' => '/dashboard',
            'idp_configuration_version_id' => $versionId,
            'expected_issuer' => $this->issuer,
        ]);
        $authBinding = SsoSecretCrypto::opaqueToken(SsoSecretCrypto::BINDING_SECRET_BYTES);
        app(AuthenticationTransactionService::class)->attachAuthBinding($created['transaction'], $authBinding);

        return [
            'tenant' => $tenant->fresh(),
            'user' => $user,
            'link' => $link,
            'created' => $created,
            'authBinding' => $authBinding,
        ];
    }

    #[Test]
    #[Group('host-profile-contract')]
    public function resolve_existing_link_only_returns_identity_not_provisioned_without_creating_link(): void
    {
        [$tenant, $user] = $this->createOrgMember('host-d10-'.uniqid().'@example.com');
        $subject = 'sub-'.Str::uuid()->toString();

        tenancy()->initialize($tenant);
        $result = app(SsoIdentityResolver::class)->resolveExistingLinkOnly(
            $tenant,
            new SsoValidatedClaims($this->issuer, $subject, $user->email, true),
            $this->issuer,
        );

        $this->assertFalse($result->succeeded());
        $this->assertSame(SsoIdentityResolutionResult::REASON_IDENTITY_NOT_PROVISIONED, $result->failureReason);
        $this->assertSame(0, TenantUserIdentity::query()->count());
        $this->assertSame(1, Membership::query()->where('user_id', $user->id)->count());
        tenancy()->end();
    }

    #[Test]
    #[Group('host-profile-contract')]
    public function resolve_existing_link_only_ignores_email_and_does_not_first_link(): void
    {
        [$tenant, $user] = $this->createOrgMember('email-attr-'.uniqid().'@example.com');
        $subject = 'sub-'.Str::uuid()->toString();

        tenancy()->initialize($tenant);
        // Verified email that WOULD first-link under Path resolve() — Host must still fail closed.
        $hostResult = app(SsoIdentityResolver::class)->resolveExistingLinkOnly(
            $tenant,
            new SsoValidatedClaims($this->issuer, $subject, $user->email, true),
            $this->issuer,
        );
        $this->assertFalse($hostResult->succeeded());
        $this->assertSame(0, TenantUserIdentity::query()->count());

        $pathResult = app(SsoIdentityResolver::class)->resolve(
            $tenant,
            new SsoValidatedClaims($this->issuer, $subject, $user->email, true),
            $this->issuer,
        );
        $this->assertTrue($pathResult->succeeded());
        $this->assertTrue($pathResult->createdLink);
        $this->assertSame(1, TenantUserIdentity::query()->where('subject', $subject)->count());
        tenancy()->end();
    }

    #[Test]
    #[Group('host-profile-contract')]
    public function host_inactive_user_or_membership_collapses_to_identity_not_provisioned(): void
    {
        [$tenant, $user] = $this->createOrgMember('inactive-'.uniqid().'@example.com');
        $subject = 'sub-'.Str::uuid()->toString();

        tenancy()->initialize($tenant);
        TenantUserIdentity::query()->create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'issuer' => $this->issuer,
            'subject' => $subject,
        ]);
        Membership::query()
            ->where('tenant_id', $tenant->id)
            ->where('user_id', $user->id)
            ->update(['status' => 'suspended']);

        $result = app(SsoIdentityResolver::class)->resolveExistingLinkOnly(
            $tenant,
            new SsoValidatedClaims($this->issuer, $subject, $user->email, true),
            $this->issuer,
        );
        $this->assertFalse($result->succeeded());
        $this->assertSame(SsoIdentityResolutionResult::REASON_IDENTITY_NOT_PROVISIONED, $result->failureReason);
        // Path resolve still exposes membership_inactive (C1 Path surface unchanged).
        $path = app(SsoIdentityResolver::class)->resolve(
            $tenant,
            new SsoValidatedClaims($this->issuer, $subject, $user->email, true),
            $this->issuer,
        );
        $this->assertSame(SsoIdentityResolutionResult::REASON_MEMBERSHIP_INACTIVE, $path->failureReason);
        tenancy()->end();
    }

    #[Test]
    #[Group('host-profile-contract')]
    public function host_resolution_failure_audit_excludes_identity_material(): void
    {
        [$tenant, $user] = $this->createOrgMember('audit-host-'.uniqid().'@example.com');
        $subject = 'sub-'.Str::uuid()->toString();
        $logged = [];

        $this->app->bind(AuditLoggerInterface::class, function () use (&$logged) {
            return new class($logged) implements AuditLoggerInterface
            {
                public function __construct(private array &$logged) {}

                public function log(string $event, array $context = []): void
                {
                    $this->logged[] = ['event' => $event, 'context' => $context];
                }

                public function logCreated(object $model): void {}

                public function logUpdated(object $model, array $oldValues, array $newValues): void {}

                public function logDeleted(object $model): void {}
            };
        });

        tenancy()->initialize($tenant);
        app(SsoIdentityResolver::class)->resolveExistingLinkOnly(
            $tenant,
            new SsoValidatedClaims($this->issuer, $subject, $user->email, true),
            $this->issuer,
        );
        tenancy()->end();

        $this->assertNotEmpty($logged);
        $this->assertSame('sso.identity.host_resolution_failed', $logged[0]['event']);
        $this->assertSame(
            SsoIdentityResolutionResult::REASON_IDENTITY_NOT_PROVISIONED,
            $logged[0]['context']['reason'] ?? null
        );
        $payload = json_encode($logged);
        $this->assertIsString($payload);
        $this->assertStringNotContainsString($user->email, $payload);
        $this->assertStringNotContainsString($subject, $payload);
        $this->assertStringNotContainsString((string) $user->id, $payload);
        $this->assertArrayNotHasKey('email', $logged[0]['context']);
        $this->assertArrayNotHasKey('subject', $logged[0]['context']);
        $this->assertArrayNotHasKey('user_id', $logged[0]['context']);
    }

    #[Test]
    #[Group('host-profile-contract')]
    public function callback_missing_link_records_identity_not_provisioned_and_no_handoff(): void
    {
        $fixture = $this->prepareAwaitingCallbackWithLink();
        tenancy()->initialize($fixture['tenant']);
        $fixture['link']->delete();
        tenancy()->end();

        $tokenSet = Mockery::mock(TokenSetInterface::class);
        $tokenSet->shouldReceive('claims')->andReturn([
            'iss' => $this->issuer,
            'sub' => 'subject-ws6',
            'email' => $fixture['user']->email,
            'email_verified' => true,
        ]);
        $mock = Mockery::mock(app(SsoAuthService::class))->makePartial();
        $mock->shouldReceive('exchangeHostAuthorizationCode')->once()->andReturn($tokenSet);
        $this->app->instance(SsoAuthService::class, $mock);

        $this->call(
            'GET',
            'https://auth.jabal.test/auth/enterprise-sso/callback?code=d10-nolink&state='.rawurlencode($fixture['created']['state']),
            cookies: [SsoBrowserBindingCookieFactory::AUTH_BINDING => $fixture['authBinding']],
            server: ['HTTP_HOST' => 'auth.jabal.test', 'SERVER_NAME' => 'auth.jabal.test', 'HTTPS' => 'on']
        )->assertRedirect();

        $txn = $fixture['created']['transaction']->fresh();
        $this->assertSame(SsoAuthenticationTransaction::STATUS_FAILED, $txn->status);
        $this->assertSame(
            SsoIdentityResolutionResult::REASON_IDENTITY_NOT_PROVISIONED,
            $txn->failure_reason
        );
        $this->assertSame(0, SsoTenantHandoff::query()->count());
        $this->assertGuest('web');
        tenancy()->initialize($fixture['tenant']);
        $this->assertSame(0, TenantUserIdentity::query()->count());
        $this->assertSame(1, User::query()->whereKey($fixture['user']->id)->count());
        tenancy()->end();
    }

    #[Test]
    #[Group('host-profile-contract')]
    public function host_surfaces_never_select_path_resolve_or_attempt_first_link(): void
    {
        $surfaces = [
            base_path('Modules/Identity/app/Services/HostEnterpriseSsoCallbackService.php'),
            base_path('Modules/Identity/app/Services/HostEnterpriseSsoHandoffService.php'),
            base_path('Modules/Identity/app/Http/Controllers/EnterpriseSsoCallbackController.php'),
            base_path('Modules/Identity/app/Http/Controllers/EnterpriseSsoHandoffController.php'),
        ];

        foreach ($surfaces as $path) {
            $source = file_get_contents($path);
            $this->assertIsString($source);
            $this->assertStringNotContainsString('attemptFirstLink(', $source, $path);
            $this->assertStringNotContainsString('->resolve($', $source, $path);
            $this->assertStringNotContainsString('resolveIdentity(', $source, $path);
        }

        $callback = file_get_contents(base_path('Modules/Identity/app/Services/HostEnterpriseSsoCallbackService.php'));
        $this->assertStringContainsString('resolveExistingLinkOnly(', $callback);

        $handoff = file_get_contents(base_path('Modules/Identity/app/Services/HostEnterpriseSsoHandoffService.php'));
        $this->assertStringNotContainsString('SsoIdentityResolver', $handoff);
        $this->assertStringNotContainsString('resolveExistingLinkOnly(', $handoff);
    }

    #[Test]
    #[Group('host-profile-contract')]
    public function soft_deleted_user_with_link_does_not_reactivate_or_issue_session_material(): void
    {
        [$tenant, $user] = $this->createOrgMember('deleted-'.uniqid().'@example.com');
        $subject = 'sub-'.Str::uuid()->toString();

        tenancy()->initialize($tenant);
        TenantUserIdentity::query()->create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'issuer' => $this->issuer,
            'subject' => $subject,
        ]);
        $user->delete();

        $result = app(SsoIdentityResolver::class)->resolveExistingLinkOnly(
            $tenant,
            new SsoValidatedClaims($this->issuer, $subject, $user->email, true),
            $this->issuer,
        );
        $this->assertFalse($result->succeeded());
        $this->assertSame(SsoIdentityResolutionResult::REASON_IDENTITY_NOT_PROVISIONED, $result->failureReason);
        $this->assertTrue($user->fresh()->trashed());
        tenancy()->end();
    }
}
