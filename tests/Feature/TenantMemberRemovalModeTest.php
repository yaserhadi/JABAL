<?php

namespace Tests\Feature;

use App\Models\Rbac\TenantPermission as Permission;
use App\Models\Rbac\TenantRole as Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Modules\Audit\Models\AuditLog;
use Modules\Billing\Models\Plan;
use Modules\Billing\Models\Subscription;
use Modules\Identity\Models\Membership;
use Modules\Identity\Services\MembershipService;
use Modules\Identity\Services\TenantInvitationService;
use Modules\Tenancy\Models\Tenant;
use Modules\Tenancy\Models\TenantSetting;
use Modules\Tenancy\Services\TenantSettingsService;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * BK-027: Configurable member removal (Permanent / Reversible), restore, delete forever.
 */
class TenantMemberRemovalModeTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;

    protected User $memberUser;

    protected Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMemberRbac();
        $this->owner = User::factory()->create();
        $this->tenant = $this->createPersonalTenant($this->owner);

        $this->memberUser = User::withoutGlobalScope('tenant')->create([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenant->id,
            'name' => 'Member User',
            'email' => 'member-'.uniqid().'@example.com',
            'password' => 'password',
        ]);
        $this->createMembership($this->memberUser, $this->tenant, 'member', 'active');
    }

    protected function seedMemberRbac(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $guard = config('auth.defaults.guard');
        foreach ([
            'member.view', 'member.assign-role', 'member.suspend', 'member.invite',
            'member.remove', 'dashboard.view', 'tenant.settings.view', 'tenant.settings.update',
        ] as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => $guard], ['name' => $name, 'guard_name' => $guard]);
        }
    }

    protected function assignAdmin(User $user, Tenant $tenant, array $extra = []): void
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->getTenantKey());
        $role = Role::firstOrCreate(
            ['name' => 'tenant-admin', 'guard_name' => config('auth.defaults.guard'), 'tenant_id' => $tenant->id],
            ['name' => 'tenant-admin', 'guard_name' => config('auth.defaults.guard'), 'tenant_id' => $tenant->id]
        );
        foreach (array_merge(['member.view', 'member.remove', 'member.invite', 'dashboard.view'], $extra) as $perm) {
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

    protected function setRemovalMode(Tenant $tenant, string $mode): void
    {
        TenantSetting::query()->updateOrCreate(
            ['tenant_id' => $tenant->id],
            ['member_removal_mode' => $mode]
        );
    }

    protected function assignMemberRole(User $user, Tenant $tenant, string $roleName): void
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->getTenantKey());
        $user->syncRoles([$roleName]);
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    }

    public function test_member_removal_mode_defaults_to_permanent_without_settings_row(): void
    {
        $service = app(TenantSettingsService::class);
        $this->assertSame('permanent', $service->memberRemovalMode($this->tenant));
    }

    public function test_member_removal_mode_null_falls_back_to_permanent(): void
    {
        TenantSetting::query()->create([
            'tenant_id' => $this->tenant->id,
            'member_removal_mode' => null,
        ]);

        $this->assertSame('permanent', app(TenantSettingsService::class)->memberRemovalMode($this->tenant));
    }

    public function test_member_removal_mode_invalid_falls_back_to_permanent(): void
    {
        TenantSetting::query()->create([
            'tenant_id' => $this->tenant->id,
            'member_removal_mode' => 'legacy_soft_delete',
        ]);

        $this->assertSame('permanent', app(TenantSettingsService::class)->memberRemovalMode($this->tenant));
    }

    public function test_permanent_remove_deletes_membership_row(): void
    {
        $this->assignAdmin($this->owner, $this->tenant);
        $membership = $this->findMembership($this->memberUser);

        $mode = app(MembershipService::class)->remove($membership, $this->tenant);

        $this->assertSame('permanent', $mode);
        $this->assertNull($this->findMembership($this->memberUser));
    }

    public function test_reversible_remove_sets_removed_status_and_timestamp(): void
    {
        $this->setRemovalMode($this->tenant, 'reversible');
        $this->assignAdmin($this->owner, $this->tenant);
        $membership = $this->findMembership($this->memberUser);

        $mode = app(MembershipService::class)->remove($membership, $this->tenant);

        $this->assertSame('reversible', $mode);
        $fresh = $this->findMembership($this->memberUser);
        $this->assertNotNull($fresh);
        $this->assertSame('removed', $fresh->status);
        $this->assertNotNull($fresh->removed_at);
    }

    public function test_removed_member_hidden_from_active_list(): void
    {
        $this->setRemovalMode($this->tenant, 'reversible');
        $this->assignAdmin($this->owner, $this->tenant);

        $membership = $this->findMembership($this->memberUser);
        app(MembershipService::class)->remove($membership, $this->tenant);

        $response = $this->actingAsTenantUser($this->owner, $this->tenant)
            ->get('/t/'.$this->tenant->id.'/members');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->has('removedMembers', 1)
            ->where('removedMembers.0.user.email', $this->memberUser->email)
            ->where('members', fn ($members) => collect($members)->every(
                fn (array $row) => ($row['user']['email'] ?? null) !== $this->memberUser->email
            )));
    }

    public function test_removed_member_cannot_access_tenant_web(): void
    {
        $this->setRemovalMode($this->tenant, 'reversible');
        $this->assignDashboardViewToUser($this->memberUser, $this->tenant);

        $membership = $this->findMembership($this->memberUser);
        app(MembershipService::class)->remove($membership, $this->tenant);

        $response = $this->actingAsTenantUser($this->memberUser, $this->tenant)
            ->get('/t/'.$this->tenant->id.'/dashboard');

        $response->assertStatus(403);
    }

    public function test_removed_member_cannot_use_api_token(): void
    {
        $this->setRemovalMode($this->tenant, 'reversible');
        $this->assignDashboardViewToUser($this->memberUser, $this->tenant);

        $membership = $this->findMembership($this->memberUser);
        app(MembershipService::class)->remove($membership, $this->tenant);

        $token = $this->memberUser->createToken('test', ['tenant:'.$this->tenant->id])->plainTextToken;

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'X-Tenant-Id' => $this->tenant->id,
            'Accept' => 'application/json',
        ])->getJson('/api/v1/me');

        $response->assertStatus(403);
    }

    public function test_remove_clears_roles_and_sets_consistent_state(): void
    {
        $this->setRemovalMode($this->tenant, 'reversible');
        $this->assignMemberRole($this->memberUser, $this->tenant, 'tenant-admin');

        $membership = $this->findMembership($this->memberUser);
        app(MembershipService::class)->remove($membership, $this->tenant);

        tenancy()->initialize($this->tenant);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->getTenantKey());
        $this->assertCount(0, $this->memberUser->fresh()->roles);
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);

        $fresh = Membership::query()
            ->withoutGlobalScope('tenant')
            ->where('user_id', $this->memberUser->id)
            ->first();
        $this->assertSame('removed', $fresh->status);
        tenancy()->end();
    }

    public function test_restore_assigns_member_role_only(): void
    {
        $this->setRemovalMode($this->tenant, 'reversible');
        $this->assignMemberRole($this->memberUser, $this->tenant, 'tenant-admin');

        $membership = $this->findMembership($this->memberUser);
        app(MembershipService::class)->remove($membership, $this->tenant);

        $removed = $this->findMembership($this->memberUser);
        app(MembershipService::class)->restore($removed, $this->tenant);

        tenancy()->initialize($this->tenant);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->getTenantKey());
        $roles = $this->memberUser->fresh()->roles->pluck('name')->all();
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);
        tenancy()->end();

        $this->assertSame(['member'], $roles);
        $this->assertSame('active', $this->findMembership($this->memberUser)->status);
        $this->assertNull($this->findMembership($this->memberUser)->removed_at);
    }

    public function test_cannot_remove_last_active_owner(): void
    {
        $this->setRemovalMode($this->tenant, 'reversible');
        $this->assignAdmin($this->owner, $this->tenant);

        $ownerMembership = $this->findMembership($this->owner);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Cannot remove the last owner of the tenant.');

        app(MembershipService::class)->remove($ownerMembership, $this->tenant);
    }

    public function test_cannot_delete_forever_last_owner_without_other_active_owner(): void
    {
        tenancy()->initialize($this->tenant);
        $ownerMembership = Membership::query()
            ->withoutGlobalScope('tenant')
            ->where('tenant_id', $this->tenant->id)
            ->where('user_id', $this->owner->id)
            ->first();
        $ownerMembership->update([
            'status' => 'removed',
            'removed_at' => now(),
        ]);
        tenancy()->end();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Cannot remove the last owner of the tenant.');

        app(MembershipService::class)->deleteForever($ownerMembership->fresh(), $this->tenant);
    }

    public function test_removed_members_do_not_count_toward_seat_limit(): void
    {
        $this->setRemovalMode($this->tenant, 'reversible');
        $this->setSeatLimit($this->tenant, 2);

        $membership = $this->findMembership($this->memberUser);
        app(MembershipService::class)->remove($membership, $this->tenant);

        $this->assertSame(1, app(MembershipService::class)->activeMemberCount($this->tenant->id));

        $removed = $this->findMembership($this->memberUser);
        app(MembershipService::class)->restore($removed, $this->tenant);

        $this->assertSame('active', $this->findMembership($this->memberUser)->status);
    }

    public function test_restore_blocked_when_seat_limit_reached(): void
    {
        $this->setRemovalMode($this->tenant, 'reversible');
        $this->setSeatLimit($this->tenant, 2);

        $membership = $this->findMembership($this->memberUser);
        app(MembershipService::class)->remove($membership, $this->tenant);

        $other = User::withoutGlobalScope('tenant')->create([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenant->id,
            'name' => 'Other Member',
            'email' => 'other-'.uniqid().'@example.com',
            'password' => 'password',
        ]);
        app(MembershipService::class)->create($other->id, $this->tenant->id, 'member', 'active');

        $removed = $this->findMembership($this->memberUser);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Seat limit reached');

        app(MembershipService::class)->restore($removed, $this->tenant);
    }

    public function test_delete_forever_removes_removed_row(): void
    {
        $this->setRemovalMode($this->tenant, 'reversible');
        $this->assignAdmin($this->owner, $this->tenant);

        $membership = $this->findMembership($this->memberUser);
        app(MembershipService::class)->remove($membership, $this->tenant);

        $removed = $this->findMembership($this->memberUser);
        app(MembershipService::class)->deleteForever($removed, $this->tenant);

        $this->assertNull($this->findMembership($this->memberUser));
    }

    public function test_invite_blocked_for_removed_member_in_reversible_mode(): void
    {
        $this->setRemovalMode($this->tenant, 'reversible');
        $this->assignAdmin($this->owner, $this->tenant);

        $membership = $this->findMembership($this->memberUser);
        app(MembershipService::class)->remove($membership, $this->tenant);

        $response = $this->actingAsTenantUser($this->owner, $this->tenant)
            ->post('/t/'.$this->tenant->id.'/members/invite', [
                'email' => $this->memberUser->email,
                'role' => 'member',
            ]);

        $response->assertSessionHasErrors('email');
    }

    public function test_can_invite_after_delete_forever(): void
    {
        $this->setRemovalMode($this->tenant, 'reversible');
        $this->assignAdmin($this->owner, $this->tenant);

        $membership = $this->findMembership($this->memberUser);
        app(MembershipService::class)->remove($membership, $this->tenant);

        $removed = $this->findMembership($this->memberUser);
        app(MembershipService::class)->deleteForever($removed, $this->tenant);

        app(TenantInvitationService::class)->createInvitation(
            $this->tenant,
            $this->memberUser->email,
            $this->owner,
            'member'
        );

        tenancy()->initialize($this->tenant);
        $this->assertTrue(
            \Modules\Identity\Models\TenantInvitation::query()
                ->where('email', $this->memberUser->email)
                ->pending()
                ->exists()
        );
        tenancy()->end();
    }

    public function test_remove_audit_includes_removal_mode(): void
    {
        $this->setRemovalMode($this->tenant, 'reversible');
        $this->assignAdmin($this->owner, $this->tenant);

        $this->actingAsTenantUser($this->owner, $this->tenant)
            ->delete('/t/'.$this->tenant->id.'/members/'.$this->memberUser->id)
            ->assertRedirect();

        $log = AuditLog::query()->where('event', 'tenant_member.removed')->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertSame('reversible', data_get($log->new_values, 'removal_mode'));
    }

    public function test_restore_audit_includes_restored_by_and_previous_removed_at(): void
    {
        $this->setRemovalMode($this->tenant, 'reversible');
        $this->assignAdmin($this->owner, $this->tenant);

        $membership = $this->findMembership($this->memberUser);
        app(MembershipService::class)->remove($membership, $this->tenant);
        $removedAt = $this->findMembership($this->memberUser)->removed_at?->toIso8601String();

        $this->actingAsTenantUser($this->owner, $this->tenant)
            ->post('/t/'.$this->tenant->id.'/members/'.$this->memberUser->id.'/restore')
            ->assertRedirect();

        $log = AuditLog::query()->where('event', 'tenant_member.restored')->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertSame($this->owner->id, data_get($log->new_values, 'restored_by'));
        $this->assertSame($removedAt, data_get($log->new_values, 'previous_removed_at'));
    }

    public function test_mode_change_audit_logged(): void
    {
        $this->assignAdmin($this->owner, $this->tenant, ['tenant.settings.update']);

        $this->actingAsTenantUser($this->owner, $this->tenant)
            ->patch('/t/'.$this->tenant->id.'/settings', [
                'member_removal_mode' => 'reversible',
                'timezone' => 'UTC',
                'locale' => 'en',
            ])
            ->assertRedirect();

        $log = AuditLog::query()->where('event', 'tenant_settings.member_removal_mode_changed')->first();
        $this->assertNotNull($log);
        $this->assertSame('permanent', data_get($log->old_values, 'old_mode'));
        $this->assertSame('reversible', data_get($log->new_values, 'new_mode'));
        $this->assertSame($this->owner->id, data_get($log->new_values, 'changed_by'));
    }

    public function test_transaction_rollback_on_restore_failure_leaves_removed_state(): void
    {
        $this->setRemovalMode($this->tenant, 'reversible');
        $this->setSeatLimit($this->tenant, 2);

        $membership = $this->findMembership($this->memberUser);
        app(MembershipService::class)->remove($membership, $this->tenant);

        $other = User::withoutGlobalScope('tenant')->create([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenant->id,
            'name' => 'Seat Filler',
            'email' => 'filler-'.uniqid().'@example.com',
            'password' => 'password',
        ]);
        app(MembershipService::class)->create($other->id, $this->tenant->id, 'member', 'active');

        $removed = $this->findMembership($this->memberUser);

        try {
            app(MembershipService::class)->restore($removed, $this->tenant);
            $this->fail('Expected seat limit exception');
        } catch (InvalidArgumentException) {
            // expected
        }

        $fresh = $this->findMembership($this->memberUser);
        $this->assertSame('removed', $fresh->status);
        $this->assertNotNull($fresh->removed_at);

        tenancy()->initialize($this->tenant);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->getTenantKey());
        $this->assertCount(0, $this->memberUser->fresh()->roles);
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);
        tenancy()->end();
    }

    protected function findMembership(User $user): ?Membership
    {
        tenancy()->initialize($this->tenant);
        try {
            return Membership::query()
                ->withoutGlobalScope('tenant')
                ->where('tenant_id', $this->tenant->id)
                ->where('user_id', $user->id)
                ->first();
        } finally {
            tenancy()->end();
        }
    }

    protected function setSeatLimit(Tenant $tenant, int $limit): void
    {
        $plan = Plan::query()->create([
            'id' => Str::uuid()->toString(),
            'code' => 'limited-'.uniqid(),
            'name' => 'Limited',
            'is_active' => true,
            'seat_limit' => $limit,
        ]);

        Subscription::query()->create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $tenant->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'seat_limit' => $limit,
            'starts_at' => now(),
        ]);
    }
}
