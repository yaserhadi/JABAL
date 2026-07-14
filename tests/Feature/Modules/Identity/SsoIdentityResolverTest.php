<?php

namespace Tests\Feature\Modules\Identity;

use App\Models\User;
use App\Support\Contracts\Audit\AuditLoggerInterface;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\Identity\Models\Membership;
use Modules\Identity\Models\TenantUserIdentity;
use Modules\Identity\Support\Sso\SsoIdentityResolutionResult;
use Modules\Identity\Support\Sso\SsoIdentityResolver;
use Modules\Identity\Support\Sso\SsoValidatedClaims;
use Modules\Tenancy\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\GrantsSsoEntitlement;
use Tests\TestCase;

class SsoIdentityResolverTest extends TestCase
{
    use GrantsSsoEntitlement;

    protected string $issuer = 'https://idp.example.com';

    protected function createOrgMember(string $email): array
    {
        $tenant = Tenant::factory()->create();
        $this->grantSsoAvailable($tenant);

        tenancy()->initialize($tenant);
        $user = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Org Member',
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

    #[Test]
    public function existing_link_requires_active_membership(): void
    {
        [$tenant, $user] = $this->createOrgMember('linked-'.uniqid().'@example.com');
        $subject = 'sub-'.Str::uuid()->toString();

        tenancy()->initialize($tenant);
        $link = TenantUserIdentity::query()->create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'issuer' => $this->issuer,
            'subject' => $subject,
        ]);
        Membership::query()
            ->where('tenant_id', $tenant->id)
            ->where('user_id', $user->id)
            ->update(['status' => 'suspended']);
        tenancy()->initialize($tenant);
        $result = app(SsoIdentityResolver::class)->resolve(
            $tenant,
            new SsoValidatedClaims($this->issuer, $subject, $user->email, true),
            $this->issuer,
        );

        $this->assertFalse($result->succeeded());
        $this->assertSame(SsoIdentityResolutionResult::REASON_MEMBERSHIP_INACTIVE, $result->failureReason);
        $this->assertSame($link->id, TenantUserIdentity::query()->where('subject', $subject)->value('id'));
        tenancy()->end();
    }

    #[Test]
    public function existing_link_succeeds_with_active_membership(): void
    {
        [$tenant, $user] = $this->createOrgMember('active-link-'.uniqid().'@example.com');
        $subject = 'sub-'.Str::uuid()->toString();

        tenancy()->initialize($tenant);
        TenantUserIdentity::query()->create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'issuer' => $this->issuer,
            'subject' => $subject,
        ]);

        $result = app(SsoIdentityResolver::class)->resolve(
            $tenant,
            new SsoValidatedClaims($this->issuer, $subject, $user->email, true),
            $this->issuer,
        );
        tenancy()->end();

        $this->assertTrue($result->succeeded());
        $this->assertFalse($result->createdLink);
        $this->assertSame($user->id, $result->user?->id);
    }

    #[Test]
    public function first_link_rejects_unverified_email(): void
    {
        [$tenant, $user] = $this->createOrgMember('unverified-'.uniqid().'@example.com');
        $subject = 'sub-'.Str::uuid()->toString();

        tenancy()->initialize($tenant);
        $result = app(SsoIdentityResolver::class)->resolve(
            $tenant,
            new SsoValidatedClaims($this->issuer, $subject, $user->email, false),
            $this->issuer,
        );

        $this->assertFalse($result->succeeded());
        $this->assertSame(SsoIdentityResolutionResult::REASON_EMAIL_NOT_VERIFIED, $result->failureReason);
        $this->assertSame(0, TenantUserIdentity::query()->where('subject', $subject)->count());
        tenancy()->end();
    }

    #[Test]
    public function first_link_rejects_when_no_email_match(): void
    {
        [$tenant, $user] = $this->createOrgMember('nomatch-'.uniqid().'@example.com');

        tenancy()->initialize($tenant);
        $result = app(SsoIdentityResolver::class)->resolve(
            $tenant,
            new SsoValidatedClaims($this->issuer, 'sub-'.Str::uuid()->toString(), 'other-'.uniqid().'@example.com', true),
            $this->issuer,
        );

        $this->assertSame(SsoIdentityResolutionResult::REASON_NO_MATCH, $result->failureReason);
        tenancy()->end();
    }

    #[Test]
    public function first_link_creates_identity_without_creating_user_or_membership(): void
    {
        [$tenant, $user] = $this->createOrgMember('first-link-'.uniqid().'@example.com');
        $subject = 'sub-'.Str::uuid()->toString();
        $userCountBefore = 0;
        $membershipCountBefore = 0;

        tenancy()->initialize($tenant);
        $userCountBefore = User::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenant->id)->count();
        $membershipCountBefore = Membership::query()->where('tenant_id', $tenant->id)->count();

        $result = app(SsoIdentityResolver::class)->resolve(
            $tenant,
            new SsoValidatedClaims($this->issuer, $subject, $user->email, true),
            $this->issuer,
        );

        $this->assertTrue($result->succeeded());
        $this->assertTrue($result->createdLink);
        $this->assertSame($userCountBefore, User::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenant->id)->count());
        $this->assertSame($membershipCountBefore, Membership::query()->where('tenant_id', $tenant->id)->count());
        tenancy()->end();
    }

    #[Test]
    public function existing_link_rejects_removed_membership(): void
    {
        [$tenant, $user] = $this->createOrgMember('removed-'.uniqid().'@example.com');
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
            ->update(['status' => 'removed']);

        $result = app(SsoIdentityResolver::class)->resolve(
            $tenant,
            new SsoValidatedClaims($this->issuer, $subject, $user->email, true),
            $this->issuer,
        );
        tenancy()->end();

        $this->assertFalse($result->succeeded());
        $this->assertSame(SsoIdentityResolutionResult::REASON_MEMBERSHIP_INACTIVE, $result->failureReason);
    }

    #[Test]
    public function existing_link_rejects_soft_deleted_user(): void
    {
        [$tenant, $user] = $this->createOrgMember('soft-deleted-'.uniqid().'@example.com');
        $subject = 'sub-'.Str::uuid()->toString();

        tenancy()->initialize($tenant);
        TenantUserIdentity::query()->create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'issuer' => $this->issuer,
            'subject' => $subject,
        ]);
        $user->delete();

        $result = app(SsoIdentityResolver::class)->resolve(
            $tenant,
            new SsoValidatedClaims($this->issuer, $subject, $user->email, true),
            $this->issuer,
        );
        tenancy()->end();

        $this->assertFalse($result->succeeded());
        $this->assertSame(SsoIdentityResolutionResult::REASON_USER_INACTIVE, $result->failureReason);
    }

    #[Test]
    public function issuer_mismatch_is_rejected(): void
    {
        [$tenant, $user] = $this->createOrgMember('issuer-'.uniqid().'@example.com');
        $subject = 'sub-'.Str::uuid()->toString();

        tenancy()->initialize($tenant);
        $result = app(SsoIdentityResolver::class)->resolve(
            $tenant,
            new SsoValidatedClaims('https://other-idp.example.com', $subject, $user->email, true),
            $this->issuer,
        );
        tenancy()->end();

        $this->assertFalse($result->succeeded());
        $this->assertSame(SsoIdentityResolutionResult::REASON_ISSUER_MISMATCH, $result->failureReason);
    }

    #[Test]
    public function first_link_rejects_when_only_inactive_membership_matches_email(): void
    {
        [$tenant, $user] = $this->createOrgMember('inactive-only-'.uniqid().'@example.com');
        $subject = 'sub-'.Str::uuid()->toString();

        tenancy()->initialize($tenant);
        Membership::query()
            ->where('tenant_id', $tenant->id)
            ->where('user_id', $user->id)
            ->update(['status' => 'suspended']);

        $result = app(SsoIdentityResolver::class)->resolve(
            $tenant,
            new SsoValidatedClaims($this->issuer, $subject, $user->email, true),
            $this->issuer,
        );

        $this->assertFalse($result->succeeded());
        $this->assertSame(SsoIdentityResolutionResult::REASON_NO_MATCH, $result->failureReason);
        $this->assertSame(0, TenantUserIdentity::query()->where('subject', $subject)->count());
        tenancy()->end();
    }

    #[Test]
    public function first_link_rejects_ambiguous_email_matches(): void
    {
        [$tenant, $userA] = $this->createOrgMember('ambiguous-'.uniqid().'@example.com');
        $sharedEmail = 'shared-'.uniqid().'@example.com';
        $subject = 'sub-'.Str::uuid()->toString();

        tenancy()->initialize($tenant);
        Schema::connection('tenant')->table('users', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'email']);
        });

        $userA->update(['email' => $sharedEmail, 'email_verified_at' => now()]);
        $userB = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Second Member',
            'email' => $sharedEmail,
            'password' => 'password',
            'email_verified_at' => now(),
        ]);
        Membership::create([
            'tenant_id' => $tenant->id,
            'user_id' => $userB->id,
            'membership_type' => 'member',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        $result = app(SsoIdentityResolver::class)->resolve(
            $tenant,
            new SsoValidatedClaims($this->issuer, $subject, $sharedEmail, true),
            $this->issuer,
        );

        $this->assertFalse($result->succeeded());
        $this->assertSame(SsoIdentityResolutionResult::REASON_AMBIGUOUS_EMAIL, $result->failureReason);
        $this->assertSame(0, TenantUserIdentity::query()->where('subject', $subject)->count());
        tenancy()->end();
    }

    #[Test]
    public function first_link_audit_payload_excludes_secrets(): void
    {
        [$tenant, $user] = $this->createOrgMember('audit-'.uniqid().'@example.com');
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
        app(SsoIdentityResolver::class)->resolve(
            $tenant,
            new SsoValidatedClaims($this->issuer, $subject, $user->email, true),
            $this->issuer,
        );
        tenancy()->end();

        $this->assertNotEmpty($logged);
        $payload = json_encode($logged);
        $this->assertStringNotContainsString('client_secret', $payload);
        $this->assertStringNotContainsString('access_token', $payload);
        $this->assertStringNotContainsString('refresh_token', $payload);
        $this->assertSame('sso.identity.first_link_created', $logged[0]['event']);
    }
}
