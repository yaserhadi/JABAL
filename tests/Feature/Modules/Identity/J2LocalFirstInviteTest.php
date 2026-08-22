<?php

namespace Tests\Feature\Modules\Identity;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Modules\Identity\Mail\TenantInvitationMail;
use Modules\Identity\Models\Membership;
use Modules\Identity\Models\TenantInvitation;
use Modules\Identity\Models\TenantUserIdentity;
use Modules\Identity\Services\TenantInvitationService;
use Modules\Tenancy\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * WAVE-3 GAP-004: J2 local-first invite (Admin creates User; Invite ≠ create User; 24h TTL).
 */
class J2LocalFirstInviteTest extends TestCase
{
    protected User $owner;

    protected Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->owner = User::factory()->create();
        $this->tenant = $this->createPersonalTenant($this->owner);
    }

    #[Test]
    public function admin_creates_user_before_invite_and_invite_binds_uuid(): void
    {
        $service = app(TenantInvitationService::class);
        $email = 'j2-'.uniqid().'@example.com';

        $user = $service->createUser($this->tenant, 'Ada', 'Lovelace', $email);
        $this->assertNotEmpty($user->id);

        $invite = $service->createInvitation($this->tenant, $user, $this->owner, 'member');

        $this->assertSame((string) $user->id, (string) $invite['invitation']->intended_user_id);
        $this->assertSame($email, $invite['invitation']->email);
        $this->assertTrue($invite['invitation']->expires_at->lessThanOrEqualTo(now()->addHours(24)->addMinute()));
        $this->assertTrue($invite['invitation']->expires_at->greaterThan(now()->addHours(23)));
    }

    #[Test]
    public function invite_acceptance_cannot_create_another_user(): void
    {
        $service = app(TenantInvitationService::class);
        $email = 'j2-complete-'.uniqid().'@example.com';
        $created = $service->createUserAndInvite($this->tenant, 'Grace', 'Hopper', $email, $this->owner);

        $userCountBefore = User::withoutGlobalScope('tenant')->where('tenant_id', $this->tenant->id)->count();

        $result = $service->completeAccountInvitation($created['invitation'], 'SecurePass1!');

        $this->assertSame((string) $created['user']->id, (string) $result['user']->id);
        $this->assertSame(
            $userCountBefore,
            User::withoutGlobalScope('tenant')->where('tenant_id', $this->tenant->id)->count()
        );
        $this->assertTrue(Hash::check('SecurePass1!', $result['user']->password));
    }

    #[Test]
    public function expired_invite_fails_closed(): void
    {
        $service = app(TenantInvitationService::class);
        $created = $service->createUserAndInvite(
            $this->tenant,
            'Exp',
            'Ired',
            'j2-exp-'.uniqid().'@example.com',
            $this->owner
        );

        tenancy()->initialize($this->tenant);
        $created['invitation']->update(['expires_at' => now()->subMinute()]);
        tenancy()->end();

        $this->assertNull($service->findValidByToken($created['plainToken']));

        $this->expectException(ValidationException::class);
        $service->completeAccountInvitation($created['invitation']->fresh(), 'SecurePass1!');
    }

    #[Test]
    public function consumed_invite_replay_fails(): void
    {
        $service = app(TenantInvitationService::class);
        $created = $service->createUserAndInvite(
            $this->tenant,
            'Rep',
            'Lay',
            'j2-replay-'.uniqid().'@example.com',
            $this->owner
        );

        $service->completeAccountInvitation($created['invitation'], 'SecurePass1!');

        $this->expectException(ValidationException::class);
        $service->completeAccountInvitation($created['invitation']->fresh(), 'SecurePass1!');
    }

    #[Test]
    public function legacy_invite_without_intended_user_fails_closed(): void
    {
        $service = app(TenantInvitationService::class);
        $email = 'j2-legacy-'.uniqid().'@example.com';

        tenancy()->initialize($this->tenant);
        $invitation = TenantInvitation::query()->create([
            'tenant_id' => $this->tenant->id,
            'email' => $email,
            'intended_user_id' => null,
            'invited_by_user_id' => $this->owner->id,
            'token_hash' => hash('sha256', 'legacy-token-wave3'),
            'role' => 'member',
            'expires_at' => now()->addHours(24),
        ]);
        tenancy()->end();

        $this->expectException(ValidationException::class);
        $service->completeAccountInvitation($invitation, 'SecurePass1!');
    }

    #[Test]
    public function invite_does_not_link_sso_or_mark_ready(): void
    {
        $service = app(TenantInvitationService::class);
        $created = $service->createUserAndInvite(
            $this->tenant,
            'No',
            'Sso',
            'j2-nosso-'.uniqid().'@example.com',
            $this->owner
        );

        $result = $service->completeAccountInvitation($created['invitation'], 'SecurePass1!');

        tenancy()->initialize($this->tenant);
        $this->assertSame(
            0,
            TenantUserIdentity::query()->where('user_id', $result['user']->id)->count()
        );
        tenancy()->end();
    }

    #[Test]
    public function invitation_ttl_uses_config_hours_not_hardcoded_days(): void
    {
        config(['tenancy.invitation_ttl_hours' => 24]);
        $this->assertSame(24, app(TenantInvitationService::class)->invitationTtlHours());

        config(['tenancy.invitation_ttl_hours' => 12]);
        $this->assertSame(12, app(TenantInvitationService::class)->invitationTtlHours());
    }

    #[Test]
    public function email_mismatch_on_accept_fails(): void
    {
        $service = app(TenantInvitationService::class);
        $created = $service->createUserAndInvite(
            $this->tenant,
            'Mis',
            'Match',
            'j2-mismatch-'.uniqid().'@example.com',
            $this->owner
        );

        $other = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'email' => 'other-'.uniqid().'@example.com',
        ]);

        $this->expectException(ValidationException::class);
        $service->acceptInvitationRecord($created['invitation'], $other);
    }

    #[Test]
    public function tenant_isolation_preserved_on_create_user(): void
    {
        $otherOwner = User::factory()->create();
        $otherTenant = $this->createPersonalTenant($otherOwner);
        $email = 'j2-iso-'.uniqid().'@example.com';

        $userA = app(TenantInvitationService::class)->createUser($this->tenant, 'Iso', 'A', $email);

        $this->expectException(ValidationException::class);
        app(TenantInvitationService::class)->createInvitation($otherTenant, $userA, $otherOwner, 'member');
    }

    #[Test]
    public function concurrent_complete_does_not_duplicate_membership(): void
    {
        $service = app(TenantInvitationService::class);
        $created = $service->createUserAndInvite(
            $this->tenant,
            'Dup',
            'Check',
            'j2-dup-'.uniqid().'@example.com',
            $this->owner
        );

        $service->completeAccountInvitation($created['invitation'], 'SecurePass1!');

        tenancy()->initialize($this->tenant);
        $this->assertSame(
            1,
            Membership::query()
                ->withoutGlobalScope('tenant')
                ->where('user_id', $created['user']->id)
                ->where('tenant_id', $this->tenant->id)
                ->count()
        );
        tenancy()->end();
    }
}
