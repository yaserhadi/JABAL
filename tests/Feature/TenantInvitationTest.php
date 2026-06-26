<?php

namespace Tests\Feature;

use App\Models\Rbac\TenantPermission as Permission;
use App\Models\Rbac\TenantRole as Role;
use App\Models\User;
use App\Support\Contracts\Audit\AuditLoggerInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Modules\Billing\Models\Plan;
use Modules\Billing\Models\Subscription;
use Modules\Identity\Http\Controllers\InvitationAcceptController;
use Modules\Identity\Mail\TenantInvitationMail;
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
        Mail::fake();
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

    public function test_non_owner_tenant_admin_cannot_invite_as_tenant_admin(): void
    {
        $admin = User::withoutGlobalScope('tenant')->create([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenant->id,
            'name' => 'Admin Not Owner',
            'email' => 'admin-invite-'.uniqid().'@example.com',
            'password' => 'password',
        ]);
        $this->createMembership($admin, $this->tenant, 'admin', 'active');
        $this->assignAdminPermissions($admin, $this->tenant);

        $response = $this->actingAsTenantUser($admin, $this->tenant)
            ->post('/t/'.$this->tenant->id.'/members/invite', [
                'email' => 'target-'.uniqid().'@example.com',
                'role' => 'tenant-admin',
            ]);

        $response->assertSessionHasErrors('role');
    }

    public function test_invalid_token_is_rejected(): void
    {
        $this->expectException(\Illuminate\Validation\ValidationException::class);

        app(TenantInvitationService::class)->acceptInvitation(
            str_repeat('a', 64),
            $this->owner
        );
    }

    public function test_expired_token_is_rejected(): void
    {
        $this->assignAdminPermissions($this->owner, $this->tenant);
        $email = 'expired-'.uniqid().'@example.com';

        $result = app(TenantInvitationService::class)->createInvitation(
            $this->tenant,
            $email,
            $this->owner,
            'member'
        );

        tenancy()->initialize($this->tenant);
        TenantInvitation::query()
            ->withoutGlobalScope('tenant')
            ->where('id', $result['invitation']->id)
            ->update(['expires_at' => now()->subDay()]);
        tenancy()->end();

        $existing = User::withoutGlobalScope('tenant')->create([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenant->id,
            'name' => 'Expired Target',
            'email' => $email,
            'password' => 'password',
        ]);

        $this->expectException(\Illuminate\Validation\ValidationException::class);

        app(TenantInvitationService::class)->acceptInvitation($result['plainToken'], $existing);
    }

    public function test_revoked_token_is_rejected(): void
    {
        $this->assignAdminPermissions($this->owner, $this->tenant);
        $email = 'revoked-'.uniqid().'@example.com';

        $result = app(TenantInvitationService::class)->createInvitation(
            $this->tenant,
            $email,
            $this->owner,
            'member'
        );

        app(TenantInvitationService::class)->revokeInvitation($result['invitation']);

        $existing = User::withoutGlobalScope('tenant')->create([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenant->id,
            'name' => 'Revoked Target',
            'email' => $email,
            'password' => 'password',
        ]);

        $this->expectException(\Illuminate\Validation\ValidationException::class);

        app(TenantInvitationService::class)->acceptInvitation($result['plainToken'], $existing);
    }

    public function test_owner_can_revoke_pending_invitation(): void
    {
        $this->assignAdminPermissions($this->owner, $this->tenant);
        $email = 'revoke-web-'.uniqid().'@example.com';

        $result = app(TenantInvitationService::class)->createInvitation(
            $this->tenant,
            $email,
            $this->owner,
            'member'
        );

        $response = $this->actingAsTenantUser($this->owner, $this->tenant)
            ->delete('/t/'.$this->tenant->id.'/members/invitations/'.$result['invitation']->id);

        $response->assertSessionHasNoErrors();

        tenancy()->initialize($this->tenant);
        $invitation = TenantInvitation::query()
            ->withoutGlobalScope('tenant')
            ->find($result['invitation']->id);
        $this->assertNotNull($invitation->revoked_at);
        tenancy()->end();
    }

    public function test_invitation_bootstrap_redirects_to_tokenless_url(): void
    {
        $this->assignAdminPermissions($this->owner, $this->tenant);
        $email = 'bootstrap-'.uniqid().'@example.com';

        $result = app(TenantInvitationService::class)->createInvitation(
            $this->tenant,
            $email,
            $this->owner,
            'member'
        );

        $response = $this->get('/invitations/'.$result['plainToken']);

        $response->assertRedirect(route('invitations.show'));
        $response->assertSessionHas(
            InvitationAcceptController::SESSION_INVITATION_ID_KEY,
            $result['invitation']->id
        );
        $session = $response->baseResponse->getSession()->all();
        $this->assertArrayHasKey(InvitationAcceptController::SESSION_INVITATION_ID_KEY, $session);
        $this->assertNotSame($result['plainToken'], $session[InvitationAcceptController::SESSION_INVITATION_ID_KEY]);
    }

    public function test_accept_routes_are_tokenless(): void
    {
        $this->assertStringNotContainsString('{token}', route('invitations.show', [], false));
        $this->assertStringNotContainsString('{token}', route('invitations.accept', [], false));
        $this->assertStringNotContainsString('{token}', route('invitations.register', [], false));
    }

    public function test_api_invite_creates_pending_invitation(): void
    {
        $this->assignAdminPermissions($this->owner, $this->tenant);
        $email = 'api-invite-'.uniqid().'@example.com';
        $token = $this->owner->createToken('test', ['tenant:'.$this->tenant->id])->plainTextToken;

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'X-Tenant-Id' => $this->tenant->id,
            'Accept' => 'application/json',
        ])->postJson('/api/v1/tenants/current/members/invite', [
            'email' => $email,
            'role' => 'member',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.email', $email);

        tenancy()->initialize($this->tenant);
        $this->assertSame(1, TenantInvitation::query()->withoutGlobalScope('tenant')->where('email', $email)->pending()->count());
        tenancy()->end();
    }

    public function test_api_non_owner_cannot_invite_as_tenant_admin(): void
    {
        $admin = User::withoutGlobalScope('tenant')->create([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenant->id,
            'name' => 'API Admin',
            'email' => 'api-admin-'.uniqid().'@example.com',
            'password' => 'password',
        ]);
        $this->createMembership($admin, $this->tenant, 'admin', 'active');
        $this->assignAdminPermissions($admin, $this->tenant);

        $token = $admin->createToken('test', ['tenant:'.$this->tenant->id])->plainTextToken;

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'X-Tenant-Id' => $this->tenant->id,
            'Accept' => 'application/json',
        ])->postJson('/api/v1/tenants/current/members/invite', [
            'email' => 'api-target-'.uniqid().'@example.com',
            'role' => 'tenant-admin',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['role']);
    }

    public function test_cross_tenant_web_invite_returns_403(): void
    {
        $otherUser = User::factory()->create();
        $otherTenant = $this->createPersonalTenant($otherUser);
        $this->assignAdminPermissions($this->owner, $this->tenant);

        $response = $this->actingAsTenantUser($this->owner, $this->tenant)
            ->post('/t/'.$otherTenant->id.'/members/invite', [
                'email' => 'cross-'.uniqid().'@example.com',
            ]);

        $response->assertStatus(403);
    }

    public function test_cross_tenant_api_invite_returns_401_or_403(): void
    {
        $otherUser = User::factory()->create();
        $otherTenant = $this->createPersonalTenant($otherUser);
        $this->assignAdminPermissions($this->owner, $this->tenant);

        $token = $this->owner->createToken('test', ['tenant:'.$this->tenant->id])->plainTextToken;

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'X-Tenant-Id' => $otherTenant->id,
            'Accept' => 'application/json',
        ])->postJson('/api/v1/tenants/current/members/invite', [
            'email' => 'api-cross-'.uniqid().'@example.com',
        ]);

        $this->assertContains($response->status(), [401, 403]);
    }

    public function test_invite_sends_email(): void
    {
        $this->assignAdminPermissions($this->owner, $this->tenant);
        $email = 'mail-invite-'.uniqid().'@example.com';

        $this->actingAsTenantUser($this->owner, $this->tenant)
            ->post('/t/'.$this->tenant->id.'/members/invite', [
                'email' => $email,
                'role' => 'member',
            ]);

        Mail::assertSent(TenantInvitationMail::class, function (TenantInvitationMail $mail) use ($email) {
            return $mail->hasTo($email);
        });
    }

    public function test_invite_email_contains_accept_url(): void
    {
        $this->assignAdminPermissions($this->owner, $this->tenant);
        $email = 'mail-url-'.uniqid().'@example.com';

        $this->actingAsTenantUser($this->owner, $this->tenant)
            ->post('/t/'.$this->tenant->id.'/members/invite', [
                'email' => $email,
            ]);

        Mail::assertSent(TenantInvitationMail::class, function (TenantInvitationMail $mail) {
            return str_contains($mail->acceptUrl, '/invitations/');
        });
    }

    public function test_invite_mail_failure_rolls_back_invitation(): void
    {
        $email = 'mail-fail-'.uniqid().'@example.com';
        $mock = \Mockery::mock(\Illuminate\Contracts\Mail\Factory::class);
        $mailer = \Mockery::mock(\Illuminate\Contracts\Mail\Mailer::class);
        $mock->shouldReceive('to')->andReturn($mailer);
        $mailer->shouldReceive('send')->andThrow(new \RuntimeException('Mail delivery failed'));
        Mail::swap($mock);

        try {
            app(TenantInvitationService::class)->createInvitation(
                $this->tenant,
                $email,
                $this->owner,
                'member'
            );
            $this->fail('Expected mail delivery failure.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Mail delivery failed', $e->getMessage());
        }

        tenancy()->initialize($this->tenant);
        $this->assertSame(
            0,
            TenantInvitation::query()->withoutGlobalScope('tenant')->where('email', $email)->count()
        );
        tenancy()->end();
    }

    public function test_resend_rotates_token_and_sends_email(): void
    {
        $this->assignAdminPermissions($this->owner, $this->tenant);
        $email = 'resend-'.uniqid().'@example.com';

        $result = app(TenantInvitationService::class)->createInvitation(
            $this->tenant,
            $email,
            $this->owner,
            'member'
        );
        $oldToken = $result['plainToken'];
        $oldHash = $result['invitation']->token_hash;

        $this->actingAsTenantUser($this->owner, $this->tenant)
            ->post('/t/'.$this->tenant->id.'/members/invitations/'.$result['invitation']->id.'/resend');

        Mail::assertSent(TenantInvitationMail::class, 2);

        tenancy()->initialize($this->tenant);
        $invitation = TenantInvitation::query()
            ->withoutGlobalScope('tenant')
            ->find($result['invitation']->id);
        $this->assertNotSame($oldHash, $invitation->token_hash);
        $this->assertNull(app(TenantInvitationService::class)->findValidByToken($oldToken));
        tenancy()->end();
    }

    public function test_resend_mail_failure_preserves_old_token(): void
    {
        $email = 'resend-fail-'.uniqid().'@example.com';
        $result = app(TenantInvitationService::class)->createInvitation(
            $this->tenant,
            $email,
            $this->owner,
            'member'
        );
        $oldHash = $result['invitation']->token_hash;
        $oldToken = $result['plainToken'];

        $mock = \Mockery::mock(\Illuminate\Contracts\Mail\Factory::class);
        $mailer = \Mockery::mock(\Illuminate\Contracts\Mail\Mailer::class);
        $mock->shouldReceive('to')->andReturn($mailer);
        $mailer->shouldReceive('send')->andThrow(new \RuntimeException('Mail delivery failed'));
        Mail::swap($mock);

        try {
            app(TenantInvitationService::class)->reissueInvitation($result['invitation'], $this->owner);
            $this->fail('Expected mail delivery failure.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Mail delivery failed', $e->getMessage());
        }

        tenancy()->initialize($this->tenant);
        $invitation = TenantInvitation::query()
            ->withoutGlobalScope('tenant')
            ->find($result['invitation']->id);
        $this->assertSame($oldHash, $invitation->token_hash);
        $this->assertNotNull(app(TenantInvitationService::class)->findValidByToken($oldToken));
        tenancy()->end();
    }

    public function test_resend_rejects_non_pending(): void
    {
        $this->assignAdminPermissions($this->owner, $this->tenant);
        $email = 'resend-revoked-'.uniqid().'@example.com';

        $result = app(TenantInvitationService::class)->createInvitation(
            $this->tenant,
            $email,
            $this->owner,
            'member'
        );

        app(TenantInvitationService::class)->revokeInvitation($result['invitation']);

        $this->expectException(\Illuminate\Validation\ValidationException::class);

        app(TenantInvitationService::class)->reissueInvitation(
            $result['invitation']->fresh(),
            $this->owner
        );
    }

    public function test_resend_requires_member_invite_permission(): void
    {
        $viewer = User::withoutGlobalScope('tenant')->create([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenant->id,
            'name' => 'Viewer',
            'email' => 'viewer-'.uniqid().'@example.com',
            'password' => 'password',
        ]);
        $this->createMembership($viewer, $this->tenant, 'member', 'active');

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->getTenantKey());
        $role = Role::firstOrCreate(
            ['name' => 'viewer-only', 'guard_name' => config('auth.defaults.guard'), 'tenant_id' => $this->tenant->id],
            ['name' => 'viewer-only', 'guard_name' => config('auth.defaults.guard'), 'tenant_id' => $this->tenant->id]
        );
        $viewPerm = Permission::findByName('member.view', config('auth.defaults.guard'));
        if ($viewPerm && ! $role->hasPermissionTo($viewPerm)) {
            $role->givePermissionTo($viewPerm);
        }
        $viewer->syncRoles([$role]);
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);

        $this->assignAdminPermissions($this->owner, $this->tenant);
        $email = 'resend-forbidden-'.uniqid().'@example.com';
        $result = app(TenantInvitationService::class)->createInvitation(
            $this->tenant,
            $email,
            $this->owner,
            'member'
        );

        $response = $this->actingAsTenantUser($viewer, $this->tenant)
            ->post('/t/'.$this->tenant->id.'/members/invitations/'.$result['invitation']->id.'/resend');

        $response->assertStatus(403);
    }

    public function test_api_resend_returns_success_json(): void
    {
        $this->assignAdminPermissions($this->owner, $this->tenant);
        $email = 'api-resend-'.uniqid().'@example.com';

        $result = app(TenantInvitationService::class)->createInvitation(
            $this->tenant,
            $email,
            $this->owner,
            'member'
        );

        $token = $this->owner->createToken('test', ['tenant:'.$this->tenant->id])->plainTextToken;

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'X-Tenant-Id' => $this->tenant->id,
            'Accept' => 'application/json',
        ])->postJson('/api/v1/tenants/current/members/invitations/'.$result['invitation']->id.'/resend');

        $response->assertStatus(200)
            ->assertJsonPath('data.email', $email);

        Mail::assertSent(TenantInvitationMail::class);
    }
}
