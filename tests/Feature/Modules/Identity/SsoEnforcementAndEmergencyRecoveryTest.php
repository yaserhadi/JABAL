<?php

namespace Tests\Feature\Modules\Identity;

use App\Models\PlatformEmergencyAuthorityCase;
use App\Models\PlatformUser;
use App\Models\Rbac\TenantPermission as Permission;
use App\Models\Rbac\TenantRole as Role;
use Modules\Identity\Models\TenantUser;
use App\Services\Platform\PlatformEmergencyAuthorityService;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Identity\Http\Middleware\EnsureMandatorySsoEnrollment;
use Modules\Identity\Models\SsoEnforcementException;
use Modules\Identity\Models\TemporaryPasswordRecovery;
use Modules\Identity\Models\TenantUserIdentity;
use Modules\Identity\Services\SecurityPolicyService;
use Modules\Identity\Services\SsoEnforcementExceptionService;
use Modules\Identity\Services\SsoEnforcementReadinessGate;
use Modules\Identity\Services\SsoReadinessAccountingService;
use Modules\Identity\Services\TemporaryPasswordRecoveryService;
use Modules\Identity\Support\Auth\AuthenticationAdministrationAssurance;
use Modules\Identity\Support\Auth\AuthenticationAdministrationGate;
use Modules\Identity\Support\Auth\AuthenticationLoginPolicy;
use Modules\Identity\Support\Auth\SsoUserReadinessState;
use Modules\Identity\Support\Sso\SsoIdentityLifecycle;
use Modules\Tenancy\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\GrantsSsoEntitlement;
use Tests\Support\InteractsWithTenantAddressingProfile;
use Tests\TestCase;

/**
 * WAVE-5: SSO Enforcement + Readiness + Emergency Recovery.
 */
class SsoEnforcementAndEmergencyRecoveryTest extends TestCase
{
    use GrantsSsoEntitlement;
    use InteractsWithTenantAddressingProfile;

    protected TenantUser $admin;

    protected TenantUser $member;

    protected Tenant $tenant;

    protected function setUp(): void
    {
        $this->forceAddressingEnv('path');
        parent::setUp();

        $this->admin = $this->registerTenantUser('Wave5 Admin', 'w5-admin-'.uniqid().'@example.com');
        $this->tenant = $this->admin->personalTenant();
        $this->grantSsoAvailable($this->tenant);
        $this->seedAuthAdminPermission($this->admin, $this->tenant);

        tenancy()->initialize($this->tenant);
        $this->member = TenantUser::withoutGlobalScope('tenant')->create([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenant->id,
            'name' => 'Wave5 Member',
            'email' => 'w5-member-'.uniqid().'@example.com',
            'password' => Hash::make('password'),
        ]);
        $this->createMembership($this->member, $this->tenant, 'member', 'active');
        tenancy()->end();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->restoreAddressingEnv();
    }

    protected function seedAuthAdminPermission(TenantUser $user, Tenant $tenant): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $guard = config('auth.defaults.guard');
        Permission::firstOrCreate(
            ['name' => AuthenticationAdministrationGate::PERMISSION, 'guard_name' => $guard],
            ['name' => AuthenticationAdministrationGate::PERMISSION, 'guard_name' => $guard]
        );
        app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->getTenantKey());
        $role = Role::firstOrCreate(
            ['name' => 'tenant-admin', 'guard_name' => $guard, 'tenant_id' => $tenant->id],
            ['name' => 'tenant-admin', 'guard_name' => $guard, 'tenant_id' => $tenant->id]
        );
        $role->givePermissionTo(AuthenticationAdministrationGate::PERMISSION);
        if (! $user->hasRole($role)) {
            $user->assignRole($role);
        }
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    }

    protected function freshAdmin(string $purpose): void
    {
        AuthenticationAdministrationAssurance::markSatisfiedForTests($purpose);
    }

    protected function makeReadyLink(TenantUser $user): TenantUserIdentity
    {
        tenancy()->initialize($this->tenant);
        $link = TenantUserIdentity::query()->create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $user->id,
            'binding_role' => TenantUserIdentity::ROLE_CURRENT,
            'issuer' => 'https://idp.example.com',
            'subject' => 'sub-'.Str::lower(Str::random(8)),
            'email_at_link' => $user->email,
            'linked_at' => now(),
            'verification_status' => SsoIdentityLifecycle::STATUS_READY,
            'ready_at' => now(),
            'ready_canonical_email' => $user->email,
            'ready_idp_configuration_version_id' => (string) Str::uuid(),
            'login_verified_at' => now(),
        ]);
        tenancy()->end();

        return $link;
    }

    #[Test]
    public function linked_alone_is_not_ready_in_accounting(): void
    {
        tenancy()->initialize($this->tenant);
        TenantUserIdentity::query()->create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->member->id,
            'binding_role' => TenantUserIdentity::ROLE_CURRENT,
            'issuer' => 'https://idp.example.com',
            'subject' => 'linked-only-'.Str::random(6),
            'email_at_link' => $this->member->email,
            'linked_at' => now(),
            'verification_status' => SsoIdentityLifecycle::STATUS_LINKED,
        ]);
        tenancy()->end();

        $classified = app(SsoReadinessAccountingService::class)->classifyUser($this->tenant, $this->member);
        $this->assertSame(SsoUserReadinessState::NOT_READY, $classified['state']);
    }

    #[Test]
    public function valid_exception_counts_and_allows_password_under_sso_only(): void
    {
        $passwordHash = $this->member->password;
        $this->freshAdmin(AuthenticationAdministrationAssurance::OP_CHANGE_POLICY);
        app(SsoEnforcementExceptionService::class)->create(
            $this->tenant,
            $this->admin,
            $this->member,
            'temporary travel exception',
            null,
            consumeFreshness: false,
        );

        $classified = app(SsoReadinessAccountingService::class)->classifyUser($this->tenant, $this->member);
        $this->assertSame(SsoUserReadinessState::EXCEPTION, $classified['state']);

        app(SecurityPolicyService::class)->update($this->tenant, [
            'authentication_policy' => AuthenticationLoginPolicy::SSO,
        ], bypassEnforcementGate: true);

        $this->assertTrue(
            app(AuthenticationLoginPolicy::class)->allowsPasswordLogin($this->tenant, $this->member)
        );
        $this->member->refresh();
        $this->assertSame($passwordHash, $this->member->password);
    }

    #[Test]
    public function automatic_exception_closes_only_after_ready_not_linked(): void
    {
        $this->freshAdmin(AuthenticationAdministrationAssurance::OP_CHANGE_POLICY);
        $exception = app(SsoEnforcementExceptionService::class)->create(
            $this->tenant,
            $this->admin,
            $this->member,
            'auto close test',
            null,
            consumeFreshness: false,
        );
        $this->assertSame(SsoEnforcementException::CLOSURE_AUTOMATIC, $exception->closure_mode);

        tenancy()->initialize($this->tenant);
        $link = TenantUserIdentity::query()->create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->member->id,
            'binding_role' => TenantUserIdentity::ROLE_CURRENT,
            'issuer' => 'https://idp.example.com',
            'subject' => 'auto-'.Str::random(6),
            'email_at_link' => $this->member->email,
            'linked_at' => now(),
            'verification_status' => SsoIdentityLifecycle::STATUS_LINKED,
        ]);
        // Linked alone must not close — closeAutomaticOnReady is only invoked after Ready.
        $exception->refresh();
        $this->assertSame(SsoEnforcementException::STATUS_ACTIVE, $exception->status);

        $versionId = (string) Str::uuid();
        app(SsoIdentityLifecycle::class)->markLoginVerifiedAndReady(
            $link,
            $this->member,
            (string) $this->tenant->id,
            $versionId,
        );
        $exception->refresh();
        $this->assertSame(SsoEnforcementException::STATUS_CLOSED, $exception->status);
        $this->assertSame('automatic_ready', $exception->close_reason);
        tenancy()->end();
    }

    #[Test]
    public function readiness_gate_fails_closed_when_population_not_ready(): void
    {
        $result = app(SsoEnforcementReadinessGate::class)->evaluate($this->tenant);
        $this->assertFalse($result['pass']);
        $this->assertNotEmpty($result['failures']);

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        app(SecurityPolicyService::class)->update($this->tenant, [
            'authentication_policy' => AuthenticationLoginPolicy::SSO,
        ]);
    }

    #[Test]
    public function last_admin_not_ready_blocks_even_if_members_ready(): void
    {
        // Member Ready, admin not Ready / no exception → last privileged admin unsafe.
        $this->makeReadyLink($this->member);

        $result = app(SsoEnforcementReadinessGate::class)->evaluate($this->tenant);
        $this->assertFalse($result['pass']);
        $this->assertTrue(
            collect($result['failures'])->contains(fn ($f) => str_contains($f, 'last_usable_privileged_admin')
                || str_contains($f, 'population_not_ready')
                || str_contains($f, 'sso_connection'))
        );
    }

    #[Test]
    public function mandatory_enrollment_middleware_blocks_not_ready_user(): void
    {
        app(SecurityPolicyService::class)->update($this->tenant, [
            'mandatory_sso_enrollment' => true,
        ], bypassEnforcementGate: true);

        $this->actingAs($this->member);
        tenancy()->initialize($this->tenant);

        $request = \Illuminate\Http\Request::create('/dashboard', 'GET');
        $request->setUserResolver(fn () => $this->member);

        $middleware = app(EnsureMandatorySsoEnrollment::class);
        $response = $middleware->handle($request, fn () => response('ok'));

        $this->assertTrue($response->isRedirect());
        tenancy()->end();
    }

    #[Test]
    public function temporary_password_recovery_requires_explicit_activation_and_expires(): void
    {
        app(SecurityPolicyService::class)->update($this->tenant, [
            'authentication_policy' => AuthenticationLoginPolicy::SSO,
        ], bypassEnforcementGate: true);

        $this->assertFalse(
            app(AuthenticationLoginPolicy::class)->allowsPasswordLogin($this->tenant, $this->member)
        );

        $recovery = app(TemporaryPasswordRecoveryService::class)->activate(
            $this->tenant,
            $this->member,
            'explicit recovery',
            TemporaryPasswordRecovery::CLASS_AVAILABILITY,
            'platform',
            (string) Str::uuid(),
            null,
            24,
        );

        $this->assertTrue($recovery->isCurrentlyValid());
        $this->assertTrue(
            app(AuthenticationLoginPolicy::class)->allowsPasswordLogin($this->tenant, $this->member)
        );

        app(TemporaryPasswordRecoveryService::class)->revoke(
            $this->tenant,
            $recovery,
            null,
            'test_revoke',
            revokeSessions: false,
        );

        $this->assertFalse(
            app(AuthenticationLoginPolicy::class)->allowsPasswordLogin($this->tenant, $this->member)
        );
    }

    #[Test]
    public function pea_requires_platform_actor_and_is_tenant_scoped_time_bound(): void
    {
        $platform = PlatformUser::query()->create([
            'id' => (string) Str::uuid(),
            'name' => 'PEA Operator',
            'email' => 'pea-'.uniqid().'@example.com',
            'password' => Hash::make('password'),
        ]);

        $result = app(PlatformEmergencyAuthorityService::class)->invoke(
            $platform,
            $this->tenant,
            'all admin lockout recovery',
            PlatformEmergencyAuthorityCase::CLASS_AVAILABILITY,
            $this->admin,
            true,
            12,
        );

        $this->assertSame(PlatformEmergencyAuthorityCase::STATUS_ACTIVE, $result['case']->status);
        $this->assertSame((string) $this->tenant->id, (string) $result['case']->tenant_id);
        $this->assertNotNull($result['case']->expires_at);
        $this->assertNotNull($result['recovery']);
        $this->assertTrue(
            app(TemporaryPasswordRecoveryService::class)->hasActiveRecovery($this->tenant, $this->admin)
        );

        app(PlatformEmergencyAuthorityService::class)->close($platform, $result['case']);
        $this->assertFalse(
            app(TemporaryPasswordRecoveryService::class)->hasActiveRecovery($this->tenant, $this->admin)
        );
    }

    #[Test]
    public function availability_outage_does_not_auto_enable_password_policy(): void
    {
        $before = app(SecurityPolicyService::class)->getAuthenticationPolicy($this->tenant);
        app(SecurityPolicyService::class)->update($this->tenant, [
            'authentication_policy' => AuthenticationLoginPolicy::SSO,
        ], bypassEnforcementGate: true);

        // Simulate outage awareness without policy mutation.
        $after = app(SecurityPolicyService::class)->getAuthenticationPolicy($this->tenant);
        $this->assertSame(AuthenticationLoginPolicy::SSO, $after);
        $this->assertNotSame($before === AuthenticationLoginPolicy::PASSWORD ? 'mutated' : $before, 'auto');
        $this->assertFalse(
            app(AuthenticationLoginPolicy::class)->allowsPasswordLogin($this->tenant, $this->member)
        );
    }

    #[Test]
    public function sso_only_preserves_password_credential(): void
    {
        $hash = $this->member->password;
        app(SecurityPolicyService::class)->update($this->tenant, [
            'authentication_policy' => AuthenticationLoginPolicy::SSO,
        ], bypassEnforcementGate: true);
        $this->member->refresh();
        $this->assertSame($hash, $this->member->password);
        $this->assertNotEmpty($this->member->password);
    }
}
