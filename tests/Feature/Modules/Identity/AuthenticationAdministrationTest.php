<?php

namespace Tests\Feature\Modules\Identity;

use App\Models\Rbac\TenantPermission as Permission;
use App\Models\Rbac\TenantRole as Role;
use Modules\Identity\Models\TenantUser;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Modules\Identity\Mail\CanonicalEmailChangeMail;
use Modules\Identity\Models\SsoIdentityBindingHistory;
use Modules\Identity\Models\SsoIdentityResetTransaction;
use Modules\Identity\Models\TenantUserIdentity;
use Modules\Identity\Models\UserMfa;
use Modules\Identity\Services\AdminMfaResetService;
use Modules\Identity\Services\AdminPasswordResetService;
use Modules\Identity\Services\AuthenticationPolicyAdministrationService;
use Modules\Identity\Services\CanonicalEmailChangeService;
use Modules\Identity\Services\IdpMigrationService;
use Modules\Identity\Services\ResetSsoService;
use Modules\Identity\Services\SecurityPolicyService;
use Modules\Identity\Support\Auth\AuthenticationAdministrationAssurance;
use Modules\Identity\Support\Auth\AuthenticationAdministrationGate;
use Modules\Identity\Support\Auth\AuthenticationLoginPolicy;
use Modules\Identity\Support\Sso\SsoIdentityLifecycle;
use Modules\Tenancy\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * WAVE-4: Authentication Administration (GAP-010 / 011 / 014).
 */
class AuthenticationAdministrationTest extends TestCase
{
    protected TenantUser $admin;

    protected TenantUser $target;

    protected Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();

        $this->admin = $this->registerTenantUser('Auth Admin', 'auth-admin-'.uniqid().'@example.com');
        $this->tenant = $this->admin->personalTenant();
        $this->seedAuthAdminPermission($this->admin, $this->tenant);

        tenancy()->initialize($this->tenant);
        $this->target = TenantUser::withoutGlobalScope('tenant')->create([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenant->id,
            'name' => 'Target User',
            'email' => 'target-'.uniqid().'@example.com',
            'password' => 'password',
        ]);
        $this->createMembership($this->target, $this->tenant, 'member', 'active');
        tenancy()->end();
    }

    protected function seedAuthAdminPermission(TenantUser $user, Tenant $tenant): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $guard = config('auth.defaults.guard');
        Permission::firstOrCreate(
            ['name' => AuthenticationAdministrationGate::PERMISSION, 'guard_name' => $guard],
            ['name' => AuthenticationAdministrationGate::PERMISSION, 'guard_name' => $guard]
        );
        Permission::firstOrCreate(
            ['name' => 'tenant.security-policy.update', 'guard_name' => $guard],
            ['name' => 'tenant.security-policy.update', 'guard_name' => $guard]
        );

        app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->getTenantKey());
        $role = Role::firstOrCreate(
            ['name' => 'tenant-admin', 'guard_name' => $guard, 'tenant_id' => $tenant->id],
            ['name' => 'tenant-admin', 'guard_name' => $guard, 'tenant_id' => $tenant->id]
        );
        $role->givePermissionTo(AuthenticationAdministrationGate::PERMISSION);
        $role->givePermissionTo('tenant.security-policy.update');
        if (! $user->hasRole($role)) {
            $user->assignRole($role);
        }
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    }

    protected function freshAdmin(string $purpose): void
    {
        AuthenticationAdministrationAssurance::markSatisfiedForTests($purpose);
    }

    protected function makeCurrentReadyLink(TenantUser $user, string $issuer = 'https://idp.example.com', string $subject = null): TenantUserIdentity
    {
        tenancy()->initialize($this->tenant);
        $link = TenantUserIdentity::query()->create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $user->id,
            'binding_role' => TenantUserIdentity::ROLE_CURRENT,
            'issuer' => $issuer,
            'subject' => $subject ?? 'sub-'.Str::lower(Str::random(8)),
            'email_at_link' => $user->email,
            'linked_at' => now(),
            'verification_status' => SsoIdentityLifecycle::STATUS_READY,
            'ready_at' => now(),
            'ready_canonical_email' => $user->email,
            'login_verified_at' => now(),
        ]);
        tenancy()->end();

        return $link;
    }

    #[Test]
    public function reset_password_initiates_without_admin_setting_password(): void
    {
        Notification::fake();
        $this->freshAdmin(AuthenticationAdministrationAssurance::OP_RESET_PASSWORD);
        $beforeHash = $this->target->password;

        $result = app(AdminPasswordResetService::class)->initiate($this->tenant, $this->admin, $this->target);

        $this->assertSame('initiated', $result['status']);
        $this->target->refresh();
        $this->assertSame($beforeHash, $this->target->password);
        $this->assertTrue(Hash::check('password', $this->target->password));
    }

    #[Test]
    public function reset_password_does_not_clear_mfa_or_unlink_sso(): void
    {
        Notification::fake();

        tenancy()->initialize($this->tenant);
        UserMfa::query()->create([
            'user_id' => $this->target->id,
            'secret' => 'TESTSECRET',
            'confirmed_at' => now(),
        ]);
        tenancy()->end();
        $link = $this->makeCurrentReadyLink($this->target);

        $this->freshAdmin(AuthenticationAdministrationAssurance::OP_RESET_PASSWORD);
        app(AdminPasswordResetService::class)->initiate($this->tenant, $this->admin, $this->target);

        tenancy()->initialize($this->tenant);
        $this->assertTrue(UserMfa::query()->where('user_id', $this->target->id)->exists());
        $this->assertNotNull(TenantUserIdentity::query()->find($link->id));
        tenancy()->end();
    }

    #[Test]
    public function reset_mfa_does_not_reset_password_or_unlink_sso(): void
    {
        tenancy()->initialize($this->tenant);
        UserMfa::query()->create([
            'user_id' => $this->target->id,
            'secret' => 'TESTSECRET',
            'confirmed_at' => now(),
        ]);
        tenancy()->end();
        $link = $this->makeCurrentReadyLink($this->target);
        $hashBefore = $this->target->password;

        $this->freshAdmin(AuthenticationAdministrationAssurance::OP_RESET_MFA);
        app(AdminMfaResetService::class)->reset($this->tenant, $this->admin, $this->target);

        $this->target->refresh();
        $this->assertSame($hashBefore, $this->target->password);
        tenancy()->initialize($this->tenant);
        $this->assertFalse(UserMfa::query()->where('user_id', $this->target->id)->exists());
        $this->assertNotNull(TenantUserIdentity::query()->find($link->id));
        tenancy()->end();
    }

    #[Test]
    public function fresh_admin_proof_required(): void
    {
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        app(AdminMfaResetService::class)->reset($this->tenant, $this->admin, $this->target);
    }

    #[Test]
    public function cross_tenant_target_denied(): void
    {
        $otherAdmin = $this->registerTenantUser('Other', 'other-'.uniqid().'@example.com');
        $otherTenant = $otherAdmin->personalTenant();

        $this->freshAdmin(AuthenticationAdministrationAssurance::OP_RESET_MFA);
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        app(AdminMfaResetService::class)->reset($this->tenant, $this->admin, $otherAdmin);
    }

    #[Test]
    public function reset_sso_preserves_current_while_candidate_pending(): void
    {
        $current = $this->makeCurrentReadyLink($this->target);
        $this->freshAdmin(AuthenticationAdministrationAssurance::OP_RESET_SSO);

        $result = app(ResetSsoService::class)->initiate($this->tenant, $this->admin, $this->target);
        $txn = $result['transaction'];

        tenancy()->initialize($this->tenant);
        $current->refresh();
        $this->assertSame(TenantUserIdentity::ROLE_CURRENT, $current->binding_role);
        $this->assertSame(SsoIdentityLifecycle::STATUS_LINKED, $current->verification_status);
        $this->assertNull($current->ready_at);
        $this->assertTrue($txn->isPending());

        $candidate = TenantUserIdentity::query()->create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->target->id,
            'binding_role' => TenantUserIdentity::ROLE_CANDIDATE,
            'issuer' => 'https://new-idp.example.com',
            'subject' => 'new-sub-'.Str::random(6),
            'email_at_link' => $this->target->email,
        ]);
        app(ResetSsoService::class)->attachCandidate($this->tenant, $txn, $candidate);
        $candidate->refresh();
        $this->assertSame(SsoIdentityLifecycle::STATUS_LINKED, $candidate->verification_status);
        $this->assertNull($candidate->ready_at);

        // Candidate failure must not destroy current.
        app(ResetSsoService::class)->recordCandidateFailure($this->tenant, $txn->fresh(), 'trust_mismatch');
        $current->refresh();
        $this->assertSame(TenantUserIdentity::ROLE_CURRENT, $current->binding_role);
        tenancy()->end();
    }

    #[Test]
    public function reset_sso_candidate_ready_promotes_and_keeps_history(): void
    {
        $current = $this->makeCurrentReadyLink($this->target);
        $this->freshAdmin(AuthenticationAdministrationAssurance::OP_RESET_SSO);
        $txn = app(ResetSsoService::class)->initiate($this->tenant, $this->admin, $this->target)['transaction'];

        tenancy()->initialize($this->tenant);
        $candidate = TenantUserIdentity::query()->create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->target->id,
            'binding_role' => TenantUserIdentity::ROLE_CANDIDATE,
            'issuer' => 'https://new-idp.example.com',
            'subject' => 'cand-'.Str::random(6),
            'email_at_link' => $this->target->email,
            'linked_at' => now(),
            'verification_status' => SsoIdentityLifecycle::STATUS_LINKED,
        ]);
        app(ResetSsoService::class)->attachCandidate($this->tenant, $txn, $candidate);

        app(SsoIdentityLifecycle::class)->markLoginVerifiedAndReady(
            $candidate->fresh(),
            $this->target,
            (string) $this->tenant->id,
            (string) Str::uuid(),
            'ordinary_sso_login',
        );

        $candidate->refresh();
        $current->refresh();
        $txn->refresh();

        $this->assertSame(TenantUserIdentity::ROLE_CURRENT, $candidate->binding_role);
        $this->assertSame(TenantUserIdentity::ROLE_HISTORICAL, $current->binding_role);
        $this->assertSame(SsoIdentityResetTransaction::STATUS_COMPLETED, $txn->status);
        $this->assertTrue(
            SsoIdentityBindingHistory::query()->where('identity_id', $current->id)->where('event', 'superseded')->exists()
        );
        $roles = $this->target->getRoleNames();
        tenancy()->end();
        $this->assertNotNull($roles);
    }

    #[Test]
    public function compromised_current_is_security_held_not_auto_restored(): void
    {
        $current = $this->makeCurrentReadyLink($this->target);
        $this->freshAdmin(AuthenticationAdministrationAssurance::OP_RESET_SSO);
        app(ResetSsoService::class)->initiate($this->tenant, $this->admin, $this->target, compromisedCurrent: true);

        tenancy()->initialize($this->tenant);
        $current->refresh();
        $this->assertSame(TenantUserIdentity::ROLE_SECURITY_HELD, $current->binding_role);
        $this->assertNotNull($current->security_held_at);
        $this->assertFalse($current->isResolvableForLogin());
        tenancy()->end();
    }

    #[Test]
    public function policy_change_preserves_password_and_requires_freshness(): void
    {
        $hash = $this->target->password;
        $this->freshAdmin(AuthenticationAdministrationAssurance::OP_CHANGE_POLICY);

        // Admin needs Ready to set SSO-only — use both instead.
        $result = app(AuthenticationPolicyAdministrationService::class)
            ->change($this->tenant, $this->admin, AuthenticationLoginPolicy::PASSWORD);

        $this->assertSame(AuthenticationLoginPolicy::PASSWORD, $result['after']);
        $this->target->refresh();
        $this->assertSame($hash, $this->target->password);
    }

    #[Test]
    public function sso_only_blocked_when_actor_not_ready(): void
    {
        $this->freshAdmin(AuthenticationAdministrationAssurance::OP_CHANGE_POLICY);
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        app(AuthenticationPolicyAdministrationService::class)
            ->change($this->tenant, $this->admin, AuthenticationLoginPolicy::SSO);
    }

    #[Test]
    public function email_change_requires_mailbox_and_couples_reset_sso_for_linked_user(): void
    {
        $this->makeCurrentReadyLink($this->target);
        $uuid = (string) $this->target->id;

        $this->freshAdmin(AuthenticationAdministrationAssurance::OP_CHANGE_EMAIL);
        $initiated = app(CanonicalEmailChangeService::class)->initiate(
            $this->tenant,
            $this->admin,
            $this->target,
            'new-'.uniqid().'@example.com',
        );

        $this->assertTrue($initiated['request']->requires_reset_sso);
        $this->assertSame($uuid, (string) $this->target->id);

        $verified = app(CanonicalEmailChangeService::class)->verifyMailbox($initiated['plain_token']);
        $this->assertSame('verified', $verified->status);

        // Email not authoritative until complete.
        $this->target->refresh();
        $this->assertNotSame($initiated['request']->proposed_email, $this->target->email);

        $this->freshAdmin(AuthenticationAdministrationAssurance::OP_CHANGE_EMAIL);
        $completed = app(CanonicalEmailChangeService::class)->complete($this->tenant, $this->admin, $verified->fresh());

        $this->assertSame($uuid, (string) $completed['user']->id);
        $this->assertSame($initiated['request']->proposed_email, $completed['user']->email);
        $this->assertArrayHasKey('reset_transaction', $completed);
        $this->assertTrue($completed['reset_transaction']->same_euid_reverification);
    }

    #[Test]
    public function path_b_bridge_is_explicit_not_automatic(): void
    {
        app(SecurityPolicyService::class)->update($this->tenant, [
            'authentication_policy' => AuthenticationLoginPolicy::SSO,
        ], bypassEnforcementGate: true);
        tenancy()->initialize($this->tenant);
        app(SecurityPolicyService::class)->update($this->tenant, [
            'authentication_policy' => AuthenticationLoginPolicy::PASSWORD,
        ], bypassEnforcementGate: true);
        tenancy()->end();

        $this->freshAdmin(AuthenticationAdministrationAssurance::OP_IDP_MIGRATION);
        $result = app(IdpMigrationService::class)->activatePathBBridge($this->tenant, $this->admin);

        $this->assertSame(AuthenticationLoginPolicy::BOTH, $result['policy_after']);
        $this->assertTrue($result['transaction']->isPending());
    }

    #[Test]
    public function path_a_does_not_flip_password_login_policy(): void
    {
        app(SecurityPolicyService::class)->update($this->tenant, [
            'authentication_policy' => AuthenticationLoginPolicy::SSO,
        ], bypassEnforcementGate: true);
        tenancy()->initialize($this->tenant);
        \Modules\Identity\Models\TenantSecurityPolicy::query()->where('tenant_id', $this->tenant->id)
            ->update(['authentication_policy' => AuthenticationLoginPolicy::SSO]);
        tenancy()->end();

        $before = app(SecurityPolicyService::class)->getAuthenticationPolicy($this->tenant);
        $this->freshAdmin(AuthenticationAdministrationAssurance::OP_IDP_MIGRATION);
        app(IdpMigrationService::class)->startPathA($this->tenant, $this->admin, $this->target);
        $after = app(SecurityPolicyService::class)->getAuthenticationPolicy($this->tenant);

        $this->assertSame($before, $after);
    }
}
