<?php

namespace Tests\Feature\Modules\Identity;

use App\Models\User;
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
        $result = app(SsoIdentityResolver::class)->resolveExistingLinkOnly(
            $tenant,
            new SsoValidatedClaims($this->issuer, $subject, $user->email, true),
            $this->issuer,
        );

        $this->assertFalse($result->succeeded());
        $this->assertSame(SsoIdentityResolutionResult::REASON_IDENTITY_NOT_PROVISIONED, $result->failureReason);
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

        $result = app(SsoIdentityResolver::class)->resolveExistingLinkOnly(
            $tenant,
            new SsoValidatedClaims($this->issuer, $subject, $user->email, true),
            $this->issuer,
        );
        tenancy()->end();

        $this->assertTrue($result->succeeded());
        $this->assertSame($user->id, $result->user?->id);
    }

    #[Test]
    public function missing_link_with_verified_email_match_fails_closed_without_creating_link(): void
    {
        [$tenant, $user] = $this->createOrgMember('verified-miss-'.uniqid().'@example.com');
        $subject = 'sub-'.Str::uuid()->toString();

        tenancy()->initialize($tenant);
        $result = app(SsoIdentityResolver::class)->resolveExistingLinkOnly(
            $tenant,
            new SsoValidatedClaims($this->issuer, $subject, $user->email, true),
            $this->issuer,
        );

        $this->assertFalse($result->succeeded());
        $this->assertSame(SsoIdentityResolutionResult::REASON_IDENTITY_NOT_PROVISIONED, $result->failureReason);
        $this->assertSame(0, TenantUserIdentity::query()->where('subject', $subject)->count());
        tenancy()->end();
    }

    #[Test]
    public function missing_link_with_unverified_email_fails_with_same_generic_reason(): void
    {
        [$tenant, $user] = $this->createOrgMember('unverified-'.uniqid().'@example.com');
        $subject = 'sub-'.Str::uuid()->toString();

        tenancy()->initialize($tenant);
        $result = app(SsoIdentityResolver::class)->resolveExistingLinkOnly(
            $tenant,
            new SsoValidatedClaims($this->issuer, $subject, $user->email, false),
            $this->issuer,
        );

        $this->assertFalse($result->succeeded());
        $this->assertSame(SsoIdentityResolutionResult::REASON_IDENTITY_NOT_PROVISIONED, $result->failureReason);
        $this->assertSame(0, TenantUserIdentity::query()->where('subject', $subject)->count());
        tenancy()->end();
    }

    #[Test]
    public function missing_link_with_ambiguous_email_fails_with_same_generic_reason(): void
    {
        [$tenant, $user] = $this->createOrgMember('ambiguous-a-'.uniqid().'@example.com');
        $subject = 'sub-'.Str::uuid()->toString();

        tenancy()->initialize($tenant);
        // Second active member with a different email — still no link for subject.
        User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Other',
            'email' => 'ambiguous-b-'.uniqid().'@example.com',
            'password' => 'password',
        ]);

        $result = app(SsoIdentityResolver::class)->resolveExistingLinkOnly(
            $tenant,
            new SsoValidatedClaims($this->issuer, $subject, $user->email, true),
            $this->issuer,
        );

        $this->assertSame(SsoIdentityResolutionResult::REASON_IDENTITY_NOT_PROVISIONED, $result->failureReason);
        $this->assertSame(0, TenantUserIdentity::query()->where('subject', $subject)->count());
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

        $result = app(SsoIdentityResolver::class)->resolveExistingLinkOnly(
            $tenant,
            new SsoValidatedClaims($this->issuer, $subject, $user->email, true),
            $this->issuer,
        );
        tenancy()->end();

        $this->assertFalse($result->succeeded());
        $this->assertSame(SsoIdentityResolutionResult::REASON_IDENTITY_NOT_PROVISIONED, $result->failureReason);
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

        $result = app(SsoIdentityResolver::class)->resolveExistingLinkOnly(
            $tenant,
            new SsoValidatedClaims($this->issuer, $subject, $user->email, true),
            $this->issuer,
        );
        tenancy()->end();

        $this->assertFalse($result->succeeded());
        $this->assertSame(SsoIdentityResolutionResult::REASON_IDENTITY_NOT_PROVISIONED, $result->failureReason);
    }

    #[Test]
    public function issuer_mismatch_is_rejected(): void
    {
        [$tenant, $user] = $this->createOrgMember('issuer-'.uniqid().'@example.com');
        $subject = 'sub-'.Str::uuid()->toString();

        tenancy()->initialize($tenant);
        $result = app(SsoIdentityResolver::class)->resolveExistingLinkOnly(
            $tenant,
            new SsoValidatedClaims('https://other-idp.example.com', $subject, $user->email, true),
            $this->issuer,
        );
        tenancy()->end();

        $this->assertFalse($result->succeeded());
        $this->assertSame(SsoIdentityResolutionResult::REASON_ISSUER_MISMATCH, $result->failureReason);
    }

    #[Test]
    public function issuer_match_wrong_subject_fails_closed(): void
    {
        [$tenant, $user] = $this->createOrgMember('wrong-sub-'.uniqid().'@example.com');
        $subject = 'sub-'.Str::uuid()->toString();

        tenancy()->initialize($tenant);
        TenantUserIdentity::query()->create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'issuer' => $this->issuer,
            'subject' => $subject,
        ]);

        $result = app(SsoIdentityResolver::class)->resolveExistingLinkOnly(
            $tenant,
            new SsoValidatedClaims($this->issuer, 'other-'.$subject, $user->email, true),
            $this->issuer,
        );
        tenancy()->end();

        $this->assertFalse($result->succeeded());
        $this->assertSame(SsoIdentityResolutionResult::REASON_IDENTITY_NOT_PROVISIONED, $result->failureReason);
    }

    #[Test]
    public function subject_match_wrong_issuer_fails_closed(): void
    {
        [$tenant, $user] = $this->createOrgMember('wrong-iss-'.uniqid().'@example.com');
        $subject = 'sub-'.Str::uuid()->toString();

        tenancy()->initialize($tenant);
        TenantUserIdentity::query()->create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'issuer' => $this->issuer,
            'subject' => $subject,
        ]);

        $result = app(SsoIdentityResolver::class)->resolveExistingLinkOnly(
            $tenant,
            new SsoValidatedClaims('https://other-idp.example.com', $subject, $user->email, true),
            $this->issuer,
        );
        tenancy()->end();

        $this->assertFalse($result->succeeded());
        $this->assertSame(SsoIdentityResolutionResult::REASON_ISSUER_MISMATCH, $result->failureReason);
    }

    #[Test]
    public function cross_tenant_identity_link_is_denied(): void
    {
        [$tenantA, $userA] = $this->createOrgMember('cross-a-'.uniqid().'@example.com');
        [$tenantB] = $this->createOrgMember('cross-b-'.uniqid().'@example.com');
        $subject = 'sub-'.Str::uuid()->toString();

        tenancy()->initialize($tenantA);
        TenantUserIdentity::query()->create([
            'tenant_id' => $tenantA->id,
            'user_id' => $userA->id,
            'issuer' => $this->issuer,
            'subject' => $subject,
        ]);
        tenancy()->end();

        tenancy()->initialize($tenantB);
        $result = app(SsoIdentityResolver::class)->resolveExistingLinkOnly(
            $tenantB,
            new SsoValidatedClaims($this->issuer, $subject, $userA->email, true),
            $this->issuer,
        );
        tenancy()->end();

        $this->assertFalse($result->succeeded());
        $this->assertSame(SsoIdentityResolutionResult::REASON_IDENTITY_NOT_PROVISIONED, $result->failureReason);
    }

    #[Test]
    public function resolver_source_never_creates_identity_links(): void
    {
        $source = file_get_contents(base_path('Modules/Identity/app/Support/Sso/SsoIdentityResolver.php'));
        $this->assertStringNotContainsString('attemptFirstLink', $source);
        $this->assertStringNotContainsString('TenantUserIdentity::query()->create', $source);
        $this->assertStringNotContainsString('first_link_created', $source);
        $this->assertStringContainsString('resolveExistingLinkOnly', $source);
    }
}
