<?php

namespace Tests\Feature\Modules\Identity;

use App\Models\User;
use Illuminate\Support\Str;
use Modules\Identity\Models\Membership;
use Modules\Identity\Models\TenantUserIdentity;
use Modules\Identity\Support\Sso\SsoIdentityLifecycle;
use Modules\Identity\Support\Sso\SsoIdentityResolutionResult;
use Modules\Identity\Support\Sso\SsoIdentityResolver;
use Modules\Identity\Support\Sso\SsoValidatedClaims;
use Modules\Tenancy\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\GrantsSsoEntitlement;
use Tests\TestCase;

/** WAVE-2 GAP-007: Linked ≠ Login Verified ≠ Ready. */
class SsoIdentityLifecycleTest extends TestCase
{
    use GrantsSsoEntitlement;

    protected string $issuer = 'https://idp.example.com';

    /**
     * @return array{0: Tenant, 1: User, 2: Membership}
     */
    protected function createMember(string $email): array
    {
        $tenant = Tenant::factory()->create();
        $this->grantSsoAvailable($tenant);

        tenancy()->initialize($tenant);
        $user = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Lifecycle User',
            'email' => $email,
            'password' => 'password',
        ]);
        $membership = Membership::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'membership_type' => 'member',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        return [$tenant, $user, $membership];
    }

    #[Test]
    public function mark_linked_does_not_set_ready_or_login_verified(): void
    {
        [$tenant, $user] = $this->createMember('linked-'.uniqid().'@example.com');
        $versionId = (string) Str::uuid();

        $link = TenantUserIdentity::query()->create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'issuer' => $this->issuer,
            'subject' => 'sub-'.Str::uuid(),
            'email_at_link' => $user->email,
        ]);

        $linked = app(SsoIdentityLifecycle::class)->markLinked($link, (string) $tenant->id, $versionId);

        $this->assertSame(SsoIdentityLifecycle::STATUS_LINKED, $linked->verification_status);
        $this->assertNotNull($linked->linked_at);
        $this->assertNull($linked->login_verified_at);
        $this->assertNull($linked->ready_at);
        $this->assertFalse(
            app(SsoIdentityLifecycle::class)->isEffectivelyReady($linked, $user, $versionId)
        );
        tenancy()->end();
    }

    #[Test]
    public function ordinary_login_success_marks_verified_and_ready_idempotently(): void
    {
        [$tenant, $user] = $this->createMember('ready-'.uniqid().'@example.com');
        $versionId = (string) Str::uuid();

        $link = TenantUserIdentity::query()->create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'issuer' => $this->issuer,
            'subject' => 'sub-'.Str::uuid(),
            'email_at_link' => $user->email,
        ]);
        app(SsoIdentityLifecycle::class)->markLinked($link, (string) $tenant->id, $versionId);

        $lifecycle = app(SsoIdentityLifecycle::class);
        $ready = $lifecycle->markLoginVerifiedAndReady($link->fresh(), $user, (string) $tenant->id, $versionId);
        $this->assertSame(SsoIdentityLifecycle::STATUS_READY, $ready->verification_status);
        $this->assertNotNull($ready->login_verified_at);
        $this->assertNotNull($ready->ready_at);
        $this->assertTrue($lifecycle->isEffectivelyReady($ready, $user, $versionId));

        $again = $lifecycle->markLoginVerifiedAndReady($ready, $user, (string) $tenant->id, $versionId);
        $this->assertSame((string) $ready->ready_at, (string) $again->ready_at);
        $this->assertSame($user->id, $again->user_id);
        tenancy()->end();
    }

    #[Test]
    public function verification_failure_does_not_disable_user_or_alter_roles(): void
    {
        [$tenant, $user] = $this->createMember('fail-'.uniqid().'@example.com');
        app(\Modules\Tenancy\Services\TenantRbacProvisioner::class)->ensureGlobalPermissions();
        app(\Modules\Tenancy\Services\TenantRbacProvisioner::class)->ensureRolesForTenant($tenant);
        $this->assignDashboardViewToUser($user, $tenant);
        $rolesBefore = $user->fresh()->getRoleNames()->sort()->values()->all();

        $link = TenantUserIdentity::query()->create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'issuer' => $this->issuer,
            'subject' => 'sub-'.Str::uuid(),
            'email_at_link' => $user->email,
        ]);
        app(SsoIdentityLifecycle::class)->markLinked($link, (string) $tenant->id, (string) Str::uuid());
        app(SsoIdentityLifecycle::class)->markLoginVerifiedAndReady(
            $link->fresh(),
            $user,
            (string) $tenant->id,
            (string) Str::uuid(),
        );

        $failed = app(SsoIdentityLifecycle::class)->markVerificationFailed(
            $link->fresh(),
            (string) $tenant->id,
            'session_register_failed',
        );

        $this->assertSame(SsoIdentityLifecycle::STATUS_VERIFICATION_FAILED, $failed->verification_status);
        $this->assertNull($failed->ready_at);
        $this->assertNotNull($user->fresh());
        $this->assertFalse($user->fresh()->trashed());
        $this->assertSame('active', Membership::query()->where('user_id', $user->id)->value('status'));
        $this->assertSame($rolesBefore, $user->fresh()->getRoleNames()->sort()->values()->all());
        $this->assertSame(1, TenantUserIdentity::query()->whereKey($failed->id)->count());
        tenancy()->end();
    }

    #[Test]
    public function trust_mismatch_marks_needs_attention_without_unlinking_or_mutating_user(): void
    {
        [$tenant, $user] = $this->createMember('attn-'.uniqid().'@example.com');
        $subject = 'sub-'.Str::uuid();
        $emailBefore = $user->email;

        $link = TenantUserIdentity::query()->create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'issuer' => $this->issuer,
            'subject' => $subject,
            'email_at_link' => $user->email,
        ]);
        app(SsoIdentityLifecycle::class)->markLinked($link, (string) $tenant->id, (string) Str::uuid());

        $result = app(SsoIdentityResolver::class)->resolveExistingLinkOnly(
            $tenant,
            new SsoValidatedClaims($this->issuer, $subject, 'other@example.com', true),
            $this->issuer,
            ['example.com'],
        );

        $this->assertFalse($result->succeeded());
        $this->assertSame(SsoIdentityResolutionResult::REASON_IDENTITY_NOT_PROVISIONED, $result->failureReason);
        $this->assertSame(SsoIdentityLifecycle::STATUS_NEEDS_ATTENTION, $link->fresh()->verification_status);
        $this->assertSame($emailBefore, $user->fresh()->email);
        $this->assertSame(1, TenantUserIdentity::query()->where('subject', $subject)->count());
        tenancy()->end();
    }

    #[Test]
    public function ready_invalidation_on_version_change_returns_to_linked(): void
    {
        [$tenant, $user] = $this->createMember('inv-'.uniqid().'@example.com');
        $versionA = (string) Str::uuid();
        $link = TenantUserIdentity::query()->create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'issuer' => $this->issuer,
            'subject' => 'sub-'.Str::uuid(),
            'email_at_link' => $user->email,
        ]);
        $lifecycle = app(SsoIdentityLifecycle::class);
        $lifecycle->markLinked($link, (string) $tenant->id, $versionA);
        $lifecycle->markLoginVerifiedAndReady($link->fresh(), $user, (string) $tenant->id, $versionA);

        $count = $lifecycle->invalidateReadyForTenant($tenant, 'idp_configuration_version_changed');
        $this->assertSame(1, $count);
        $fresh = $link->fresh();
        $this->assertSame(SsoIdentityLifecycle::STATUS_LINKED, $fresh->verification_status);
        $this->assertNull($fresh->ready_at);
        $this->assertNull($fresh->login_verified_at);
        $this->assertTrue($lifecycle->requiresOrdinarySessionProof($fresh, $user, $versionA));
        tenancy()->end();
    }

    #[Test]
    public function email_drift_makes_ready_not_effective_without_unlinking(): void
    {
        [$tenant, $user] = $this->createMember('drift-'.uniqid().'@example.com');
        $versionId = (string) Str::uuid();
        $link = TenantUserIdentity::query()->create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'issuer' => $this->issuer,
            'subject' => 'sub-'.Str::uuid(),
            'email_at_link' => $user->email,
        ]);
        $lifecycle = app(SsoIdentityLifecycle::class);
        $lifecycle->markLinked($link, (string) $tenant->id, $versionId);
        $lifecycle->markLoginVerifiedAndReady($link->fresh(), $user, (string) $tenant->id, $versionId);

        $user->forceFill(['email' => 'changed-'.uniqid().'@example.com'])->save();
        $this->assertFalse($lifecycle->isEffectivelyReady($link->fresh(), $user->fresh(), $versionId));
        $this->assertSame(1, TenantUserIdentity::query()->count());
        tenancy()->end();
    }

    #[Test]
    public function public_status_distinguishes_linked_verified_ready_and_attention(): void
    {
        [$tenant, $user] = $this->createMember('pub-'.uniqid().'@example.com');
        $versionId = (string) Str::uuid();
        $link = TenantUserIdentity::query()->create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'issuer' => $this->issuer,
            'subject' => 'sub-'.Str::uuid(),
            'email_at_link' => $user->email,
        ]);
        $lifecycle = app(SsoIdentityLifecycle::class);
        $lifecycle->markLinked($link, (string) $tenant->id, $versionId);

        $linked = $lifecycle->publicStatus($link->fresh(), $user, $versionId);
        $this->assertTrue($linked['linked']);
        $this->assertFalse($linked['ready']);
        $this->assertSame(SsoIdentityLifecycle::STATUS_LINKED, $linked['verification_status']);

        $lifecycle->markLoginVerifiedAndReady($link->fresh(), $user, (string) $tenant->id, $versionId);
        $ready = $lifecycle->publicStatus($link->fresh(), $user, $versionId);
        $this->assertTrue($ready['ready']);
        $this->assertTrue($ready['login_verified']);
        $this->assertSame(SsoIdentityLifecycle::STATUS_READY, $ready['verification_status']);

        $lifecycle->markVerificationFailed($link->fresh(), (string) $tenant->id, 'session_register_failed');
        $failed = $lifecycle->publicStatus($link->fresh(), $user, $versionId);
        $this->assertTrue($failed['needs_attention']);
        $this->assertFalse($failed['ready']);
        tenancy()->end();
    }
}
