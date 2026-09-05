<?php

namespace Tests\Feature;

use App\Models\Rbac\TenantPermission as Permission;
use App\Models\Rbac\TenantRole as Role;
use Modules\Identity\Models\TenantUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Modules\Audit\Models\AuditLog;
use Modules\Tenancy\Models\Tenant;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * BK-020: Tenant activity auditing — tenant-scoped timeline UI.
 */
class TenantAuditTest extends TestCase
{
    use RefreshDatabase;

    protected TenantUser $owner;

    protected TenantUser $memberUser;

    protected Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->owner = TenantUser::factory()->create();
        $this->tenant = $this->createPersonalTenant($this->owner);

        $this->memberUser = TenantUser::withoutGlobalScope('tenant')->create([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenant->id,
            'name' => 'Member User',
            'email' => 'member-'.uniqid().'@example.com',
            'password' => 'password',
        ]);
        $this->createMembership($this->memberUser, $this->tenant, 'member', 'active');
        $this->assignMemberRole($this->memberUser, ['dashboard.view']);
    }

    /**
     * @param  array<int, string>  $permissions
     */
    protected function assignMemberRole(TenantUser $user, array $permissions): void
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->getTenantKey());
        $role = Role::firstOrCreate(
            ['name' => 'member', 'guard_name' => config('auth.defaults.guard'), 'tenant_id' => $this->tenant->id],
            ['name' => 'member', 'guard_name' => config('auth.defaults.guard'), 'tenant_id' => $this->tenant->id]
        );
        foreach ($permissions as $perm) {
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

    protected function createAuditLog(Tenant $tenant, array $overrides = []): AuditLog
    {
        return AuditLog::create(array_merge([
            'tenant_id' => $tenant->id,
            'actor_id' => $this->owner->id,
            'actor_type' => 'user',
            'event' => 'tenant_member.invited',
            'auditable_type' => 'Modules\\Identity\\Models\\TenantInvitation',
            'auditable_id' => (string) Str::uuid(),
            'new_values' => [
                'email' => 'invitee@example.com',
                'invited_by_user_id' => $this->owner->id,
            ],
            'metadata' => [
                'ip' => '127.0.0.1',
                'request_id' => 'req-test',
                'user_agent' => 'PHPUnit',
            ],
            'created_at' => now(),
        ], $overrides));
    }

    public function test_tenant_admin_can_view_audit_page(): void
    {
        $this->createAuditLog($this->tenant);

        $response = $this->actingAsTenantUser($this->owner, $this->tenant)
            ->get('/t/'.$this->tenant->id.'/audit');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('TenantAudit/Index')
            ->has('logs.data', 1)
            ->where('logs.data.0.event', 'tenant_member.invited'));
    }

    public function test_member_without_permission_denied(): void
    {
        $response = $this->actingAsTenantUser($this->memberUser, $this->tenant)
            ->get('/t/'.$this->tenant->id.'/audit');

        $response->assertStatus(403);
    }

    public function test_tenant_isolation(): void
    {
        $otherOwner = TenantUser::factory()->create();
        $otherTenant = $this->createPersonalTenant($otherOwner);

        $this->createAuditLog($this->tenant, [
            'new_values' => ['email' => 'visible@example.com'],
        ]);
        $this->createAuditLog($otherTenant, [
            'new_values' => ['email' => 'hidden@example.com'],
        ]);

        $response = $this->actingAsTenantUser($this->owner, $this->tenant)
            ->get('/t/'.$this->tenant->id.'/audit');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->has('logs.data', 1)
            ->where('logs.data.0.target.email', 'visible@example.com'));
    }

    public function test_invitation_events_visible_after_invite_flow(): void
    {
        $email = 'timeline-'.uniqid().'@example.com';

        $inviteResponse = $this->actingAsTenantUser($this->owner, $this->tenant)
            ->post('/t/'.$this->tenant->id.'/members/invite', [
                'email' => $email,
                'role' => 'member',
            ]);

        $inviteResponse->assertSessionHasNoErrors();

        $this->assertDatabaseHas('audit_logs', [
            'tenant_id' => $this->tenant->id,
            'event' => 'tenant_member.invited',
        ], 'central');

        $response = $this->actingAsTenantUser($this->owner, $this->tenant)
            ->get('/t/'.$this->tenant->id.'/audit?event=tenant_member.invited');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->where('logs.data', function ($rows) use ($email) {
                return collect($rows)->contains(
                    fn (array $row) => $row['event'] === 'tenant_member.invited'
                        && ($row['target']['email'] ?? null) === $email
                );
            }));
    }

    public function test_cross_tenant_url_blocked(): void
    {
        $otherOwner = TenantUser::factory()->create();
        $otherTenant = $this->createPersonalTenant($otherOwner);

        $response = $this->actingAsTenantUser($this->owner, $this->tenant)
            ->get('/t/'.$otherTenant->id.'/audit');

        $response->assertStatus(403);
    }

    public function test_actor_deleted_still_renders(): void
    {
        $deletedActorId = (string) Str::uuid();
        $deletedActor = TenantUser::withoutGlobalScope('tenant')->create([
            'id' => $deletedActorId,
            'tenant_id' => $this->tenant->id,
            'name' => 'Deleted Actor',
            'email' => 'deleted-'.uniqid().'@example.com',
            'password' => 'password',
        ]);

        $this->createAuditLog($this->tenant, [
            'actor_id' => $deletedActorId,
        ]);

        $deletedActor->delete();

        $response = $this->actingAsTenantUser($this->owner, $this->tenant)
            ->get('/t/'.$this->tenant->id.'/audit');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->where('logs.data.0.actor.label', 'Former member'));
    }

    public function test_row_contains_no_secrets(): void
    {
        $this->createAuditLog($this->tenant, [
            'new_values' => [
                'email' => 'safe@example.com',
                'token' => 'secret-token-value',
                'token_hash' => 'hashed',
                'plainToken' => 'plain',
                'password' => 'pass',
                'secret' => 'shh',
                'api_key' => 'key-123',
                'hash_id' => 'benign-hash-id',
            ],
            'metadata' => [
                'ip' => '127.0.0.1',
                'request_id' => 'req-1',
                'user_agent' => 'PHPUnit',
                'secret' => 'meta-secret',
            ],
        ]);

        $response = $this->actingAsTenantUser($this->owner, $this->tenant)
            ->get('/t/'.$this->tenant->id.'/audit');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->has('logs.data', 1)
            ->where('logs.data', function ($rows) {
                $encoded = json_encode($rows);
                $forbidden = ['secret-token-value', 'hashed', 'plain', 'pass', 'shh', 'key-123', 'meta-secret'];
                foreach ($forbidden as $fragment) {
                    if (str_contains($encoded, $fragment)) {
                        return false;
                    }
                }

                return str_contains($encoded, 'safe@example.com');
            }));
    }
}
