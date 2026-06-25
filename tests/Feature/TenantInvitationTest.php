<?php

namespace Tests\Feature;

use App\Models\Rbac\TenantPermission as Permission;
use App\Models\Rbac\TenantRole as Role;
use App\Models\User;
use App\Support\Contracts\Audit\AuditLoggerInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\Billing\Models\Plan;
use Modules\Billing\Models\Subscription;
use Modules\Identity\Models\Membership;
use Modules\Identity\Models\TenantInvitation;
use Modules\Identity\Services\TenantInvitationService;
use Modules\Tenancy\Models\Tenant;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class TenantInvitationTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;

    protected Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMemberRbac();
        $this->owner = User::factory()->create();
        $this->tenant = $this->createPersonalTenant($this->owner);
    }

    protected function seedMemberRbac(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $guard = config('auth.defaults.guard');
        foreach ([
            'member.view', 'member.assign-role', 'member.suspend',
            'member.invite', 'member.remove', 'dashboard.view',
        ] as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => $guard], ['name' => $name, 'guard_name' => $guard]);
        }
    }

    protected function assignAdminPermissions(User $user, Tenant $tenant): void
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->getTenantKey());
        $role = Role::firstOrCreate(
            ['name' => 'tenant-admin', 'guard_name' => config('auth.defaults.guard'), 'tenant_id' => $tenant->id],
            ['name' => 'tenant-admin', 'guard_name' => config('auth.defaults.guard'), 'tenant_id' => $tenant->id]
        );
        foreach (['member.view', 'member.invite', 'member.remove', 'member.suspend', 'dashboard.view'] as $perm) {
            $p = Permission::findByName($perm, config('auth.defaults.guard'));
            if ($p && ! $role->hasPermissionTo($p)) {
                $role->givePermissionTo($p);
            }
        }
        if (! $user->hasRole($role)) {
            $user->assignRole($role);
        }
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    }

    public function test_invite_creates_pending_invitation_without_membership(): void
    {
        $this->assignAdminPermissions($this->owner, $this->tenant);
        $email = 'invitee-'.uniqid().'@example.com';

        $response = $this->actingAsTenantUser($this->owner, $this->tenant)
            ->post('/t/'.$this->tenant->id.'/members/invite', [
                'email' => $email,
                'role' => 'member',
            ]);

        $response->assertSessionHasNoErrors();
        $response->assertSessionHas('inviteUrl');

        tenancy()->initialize($this->tenant);
        $this->assertSame(1, TenantInvitation::query()->withoutGlobalScope('tenant')->where('email', $email)->pending()->count());
        $this->assertFalse(
            User::withoutGlobalScope('tenant')->where('email', $email)->exists()
        );
        tenancy()->end();
    }

    public function test_invite_audit_does_not_contain_raw_token(): void
    {
        $this->assignAdminPermissions($this->owner, $this->tenant);
        $logged = [];
        $this->app->bind(AuditLoggerInterface::class, function () use (&$logged) {
            return new class($logged) implements AuditLoggerInterface
            {
                public function __construct(private array &$logged) {}

                public function log(string $event, array $context = []): void
                {
                    $this->logged[] = compact('event', 'context');
                }

                public function logCreated(object $model): void {}

                public function logUpdated(object $model, array $oldValues, array $newValues): void {}

                public function logDeleted(object $model): void {}
            };
        });

        $this->actingAsTenantUser($this->owner, $this->tenant)
            ->post('/t/'.$this->tenant->id.'/members/invite', [
                'email' => 'audit-'.uniqid().'@example.com',
            ]);

        $inviteLog = collect($logged)->firstWhere('event', 'tenant_member.invited');
        $this->assertNotNull($inviteLog);
        $encoded = json_encode($inviteLog);
        $this->assertStringNotContainsString('token', strtolower($encoded));
    }

    public function test_register_and_accept_creates_minimal_joiner_not_personal_tenant(): void
    {
        $this->assignAdminPermissions($this->owner, $this->tenant);
        $email = 'joiner-'.uniqid().'@example.com';

        $result = app(TenantInvitationService::class)->createInvitation(
            $this->tenant,
            $email,
            $this->owner,
            'member'
        );

        $tenantCountBefore = Tenant::query()->count();

        $acceptResult = app(TenantInvitationService::class)->registerAndAccept(
            $result['plainToken'],
            'Joiner User',
            'password123'
        );

        $this->assertSame($tenantCountBefore, Tenant::query()->count());
        $this->assertSame($this->tenant->id, $acceptResult['user']->tenant_id);
        $this->assertFalse(
            Tenant::query()->where('type', 'personal')->where('created_by', $acceptResult['user']->id)->exists()
        );

        tenancy()->initialize($this->tenant);
        $membership = Membership::query()
            ->withoutGlobalScope('tenant')
            ->where('user_id', $acceptResult['user']->id)
            ->first();
        $this->assertNotNull($membership);
        $this->assertSame('member', $membership->membership_type);
        $this->assertSame('active', $membership->status);
        tenancy()->end();
    }

    public function test_existing_user_can_accept_invitation(): void
    {
        $existing = User::withoutGlobalScope('tenant')->create([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenant->id,
            'name' => 'Existing',
            'email' => 'existing-'.uniqid().'@example.com',
            'password' => 'password',
        ]);

        $result = app(TenantInvitationService::class)->createInvitation(
            $this->tenant,
            $existing->email,
            $this->owner,
            'member'
        );

        tenancy()->initialize($this->tenant);
        $membership = app(TenantInvitationService::class)->acceptInvitation($result['plainToken'], $existing);
        $this->assertSame('active', $membership->status);
        tenancy()->end();
    }

    public function test_seat_limit_blocks_invite_when_at_capacity(): void
    {
        $plan = Plan::query()->create([
            'id' => Str::uuid()->toString(),
            'code' => 'solo-invite',
            'name' => 'Solo Invite',
            'is_active' => true,
            'seat_limit' => 1,
        ]);

        Subscription::query()->create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $this->tenant->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'starts_at' => now(),
        ]);

        $this->assignAdminPermissions($this->owner, $this->tenant);

        $response = $this->actingAsTenantUser($this->owner, $this->tenant)
            ->post('/t/'.$this->tenant->id.'/members/invite', [
                'email' => 'blocked-'.uniqid().'@example.com',
            ]);

        $response->assertSessionHasErrors();
    }

    public function test_owner_can_remove_member(): void
    {
        $this->assignAdminPermissions($this->owner, $this->tenant);

        $member = User::withoutGlobalScope('tenant')->create([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenant->id,
            'name' => 'Remove Me',
            'email' => 'remove-'.uniqid().'@example.com',
            'password' => 'password',
        ]);
        $this->createMembership($member, $this->tenant, 'member', 'active');

        $response = $this->actingAsTenantUser($this->owner, $this->tenant)
            ->delete('/t/'.$this->tenant->id.'/members/'.$member->id);

        $response->assertSessionHasNoErrors();

        tenancy()->initialize($this->tenant);
        $this->assertNull(
            Membership::query()->withoutGlobalScope('tenant')->where('user_id', $member->id)->first()
        );
        tenancy()->end();
    }

    public function test_cannot_remove_last_owner(): void
    {
        $this->assignAdminPermissions($this->owner, $this->tenant);

        $response = $this->actingAsTenantUser($this->owner, $this->tenant)
            ->delete('/t/'.$this->tenant->id.'/members/'.$this->owner->id);

        $response->assertSessionHasErrors();
    }

    public function test_owner_can_transfer_ownership(): void
    {
        $this->assignAdminPermissions($this->owner, $this->tenant);

        $member = User::withoutGlobalScope('tenant')->create([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenant->id,
            'name' => 'Successor',
            'email' => 'successor-'.uniqid().'@example.com',
            'password' => 'password',
        ]);
        $this->createMembership($member, $this->tenant, 'member', 'active');

        $response = $this->actingAsTenantUser($this->owner, $this->tenant)
            ->post('/t/'.$this->tenant->id.'/members/'.$member->id.'/transfer-ownership');

        $response->assertSessionHasNoErrors();

        tenancy()->initialize($this->tenant);
        $ownerMembership = Membership::query()->withoutGlobalScope('tenant')->where('user_id', $this->owner->id)->first();
        $targetMembership = Membership::query()->withoutGlobalScope('tenant')->where('user_id', $member->id)->first();
        $this->assertSame('member', $ownerMembership->membership_type);
        $this->assertSame('owner', $targetMembership->membership_type);
        tenancy()->end();
    }

    public function test_non_owner_cannot_transfer_ownership(): void
    {
        $admin = User::withoutGlobalScope('tenant')->create([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenant->id,
            'name' => 'Admin Not Owner',
            'email' => 'admin-'.uniqid().'@example.com',
            'password' => 'password',
        ]);
        $this->createMembership($admin, $this->tenant, 'admin', 'active');
        $this->assignAdminPermissions($admin, $this->tenant);

        $member = User::withoutGlobalScope('tenant')->create([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenant->id,
            'name' => 'Member',
            'email' => 'member-'.uniqid().'@example.com',
            'password' => 'password',
        ]);
        $this->createMembership($member, $this->tenant, 'member', 'active');

        $response = $this->actingAsTenantUser($admin, $this->tenant)
            ->post('/t/'.$this->tenant->id.'/members/'.$member->id.'/transfer-ownership');

        $response->assertStatus(403);
    }
}
