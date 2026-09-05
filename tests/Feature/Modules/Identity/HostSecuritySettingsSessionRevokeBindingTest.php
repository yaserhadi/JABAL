<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Identity;

use App\Models\Rbac\TenantRole as Role;
use Modules\Identity\Models\TenantUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\Identity\Models\Membership;
use Modules\Identity\Models\UserSession;
use Modules\Tenancy\Models\Tenant;
use Modules\Tenancy\Services\TenantDomainProvisioner;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\InteractsWithDedicatedSecurityRbac;
use Tests\Support\InteractsWithTenantAddressingProfile;
use Tests\TestCase;

/**
 * BK-108 — Host session revoke must bind {session} by name; tenant_label must never become session id.
 */
#[Group('host-profile-contract')]
class HostSecuritySettingsSessionRevokeBindingTest extends TestCase
{
    use InteractsWithDedicatedSecurityRbac;
    use InteractsWithTenantAddressingProfile;
    use RefreshDatabase;

    protected function setUp(): void
    {
        $this->forceAddressingEnv('host');
        parent::setUp();
        $this->seedSecurityPolicyRbac();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->restoreAddressingEnv();
    }

    /**
     * @return array{tenant: Tenant, member: User, admin: User, otherTenant: Tenant, otherUser: User, host: string}
     */
    protected function prepareFixture(): array
    {
        $admin = $this->registerTenantUser('Admin', 'host-admin-'.uniqid().'@example.com');
        $tenant = $admin->personalTenant();
        $this->assignSecurityPolicyAdmin($admin, $tenant);
        app(TenantDomainProvisioner::class)->ensurePlatformSubdomain($tenant);

        tenancy()->initialize($tenant);
        $member = TenantUser::withoutGlobalScope('tenant')->create([
            'id' => (string) Str::uuid(),
            'tenant_id' => $tenant->id,
            'name' => 'Member User',
            'email' => 'host-member-'.uniqid().'@example.com',
            'password' => 'password',
        ]);
        Membership::create([
            'tenant_id' => $tenant->id,
            'user_id' => $member->id,
            'membership_type' => 'member',
            'status' => 'active',
            'joined_at' => now(),
        ]);
        $this->assignMemberRole($member, $tenant);
        tenancy()->end();

        $otherAdmin = $this->registerTenantUser('Other Admin', 'host-other-admin-'.uniqid().'@example.com');
        $otherTenant = $otherAdmin->personalTenant();
        app(TenantDomainProvisioner::class)->ensurePlatformSubdomain($otherTenant);

        tenancy()->initialize($otherTenant);
        $otherUser = TenantUser::withoutGlobalScope('tenant')->create([
            'id' => (string) Str::uuid(),
            'tenant_id' => $otherTenant->id,
            'name' => 'Other User',
            'email' => 'host-other-'.uniqid().'@example.com',
            'password' => 'password',
        ]);
        Membership::create([
            'tenant_id' => $otherTenant->id,
            'user_id' => $otherUser->id,
            'membership_type' => 'member',
            'status' => 'active',
            'joined_at' => now(),
        ]);
        tenancy()->end();

        return [
            'tenant' => $tenant,
            'member' => $member,
            'admin' => $admin,
            'otherTenant' => $otherTenant,
            'otherUser' => $otherUser,
            'host' => $tenant->slug.'.jabal.test',
        ];
    }

    #[Test]
    public function host_own_session_revoke_uses_named_session_not_tenant_label(): void
    {
        $fx = $this->prepareFixture();

        tenancy()->initialize($fx['tenant']);
        $record = UserSession::create([
            'tenant_id' => $fx['tenant']->id,
            'user_id' => $fx['member']->id,
            'session_id' => 'host-other-device',
            'last_activity_at' => now(),
            'logged_in_at' => now(),
        ]);
        tenancy()->end();

        $response = $this->actingAsTenantUser($fx['member'], $fx['tenant'])
            ->delete('https://'.$fx['host'].'/security/settings/sessions/'.$record->id);

        $response->assertRedirect();
        $this->assertNotEquals(500, $response->status());

        tenancy()->initialize($fx['tenant']);
        $this->assertNotNull($record->refresh()->revoked_at);
        tenancy()->end();
    }

    #[Test]
    public function host_rejects_another_users_session_revoke(): void
    {
        $fx = $this->prepareFixture();

        tenancy()->initialize($fx['tenant']);
        $adminSession = UserSession::create([
            'tenant_id' => $fx['tenant']->id,
            'user_id' => $fx['admin']->id,
            'session_id' => 'host-admin-only',
            'last_activity_at' => now(),
            'logged_in_at' => now(),
        ]);
        tenancy()->end();

        $response = $this->actingAsTenantUser($fx['member'], $fx['tenant'])
            ->delete('https://'.$fx['host'].'/security/settings/sessions/'.$adminSession->id);

        $response->assertNotFound();
    }

    #[Test]
    public function host_rejects_cross_tenant_session_revoke(): void
    {
        $fx = $this->prepareFixture();

        tenancy()->initialize($fx['otherTenant']);
        $otherSession = UserSession::create([
            'tenant_id' => $fx['otherTenant']->id,
            'user_id' => $fx['otherUser']->id,
            'session_id' => 'host-cross-tenant',
            'last_activity_at' => now(),
            'logged_in_at' => now(),
        ]);
        tenancy()->end();

        $response = $this->actingAsTenantUser($fx['member'], $fx['tenant'])
            ->delete('https://'.$fx['host'].'/security/settings/sessions/'.$otherSession->id);

        $response->assertNotFound();
    }

    #[Test]
    public function host_tenant_label_never_becomes_session_id(): void
    {
        $fx = $this->prepareFixture();

        // Deliberately omit a real session UUID — if tenant_label were injected as $session,
        // lookup would use the slug and typically 500 (invalid uuid) instead of clean 404.
        $response = $this->actingAsTenantUser($fx['member'], $fx['tenant'])
            ->delete('https://'.$fx['host'].'/security/settings/sessions/'.(string) Str::uuid());

        $response->assertNotFound();
        $this->assertNotEquals(500, $response->status());
    }

    protected function assignMemberRole(TenantUser $user, Tenant $tenant): void
    {
        $registrar = app(PermissionRegistrar::class);
        $registrar->setPermissionsTeamId($tenant->getTenantKey());
        Role::findOrCreate('member', 'web');
        $user->assignRole('member');
    }
}
