<?php

namespace Tests\Feature\Modules\Identity;

use App\Models\User;
use Illuminate\Support\Str;
use Modules\Billing\Models\Entitlement;
use Modules\Billing\Models\Plan;
use Modules\Billing\Models\Subscription;
use Modules\Identity\Models\Membership;
use Modules\Identity\Models\TenantUserIdentity;
use Modules\Identity\Services\MfaService;
use Modules\Identity\Services\SecurityPolicyService;
use Modules\Identity\Services\SsoAuthService;
use Modules\Identity\Services\SsoConfigService;
use Modules\Identity\Support\Sso\SsoAuthorizationState;
use Modules\Identity\Support\Sso\SsoIdentityResolutionResult;
use Modules\Tenancy\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

/** BK-008 MFA step-up — SSO must not bypass existing MFA middleware. */
class SsoMfaStepUpTest extends TestCase
{
    use \Tests\Support\SkipsPathEnterpriseSsoUnderHostProfile;

    protected function setUp(): void
    {
        parent::setUp();
        $this->skipPathEnterpriseSsoWhenHostProfile();
    }

    /**
     * @return array{0: Tenant, 1: User}
     */
    protected function createOrgTenantWithMfaRequiredMember(): array
    {
        $tenant = Tenant::factory()->create([
            'status' => 'active',
        ]);
        $this->grantSsoAndMfaEntitlements($tenant, required: true);

        tenancy()->initialize($tenant);
        $user = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'SSO MFA User',
            'email' => 'sso-mfa-'.uniqid().'@example.com',
            'password' => 'password',
        ]);
        Membership::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'membership_type' => 'member',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        $mfa = app(MfaService::class);
        $setup = $mfa->beginEnrollment($user);
        $code = (new Google2FA)->getCurrentOtp($setup['secret']);
        $mfa->confirmEnrollment($user, $code);

        app(SsoConfigService::class)->update($tenant, [
            'enabled' => true,
            'issuer_url' => 'https://login.microsoftonline.com/tenant/v2.0',
            'client_id' => 'client-id',
            'client_secret' => 'client-secret',
        ]);
        tenancy()->end();

        return [$tenant, $user];
    }

    #[Test]
    public function sso_callback_does_not_set_mfa_verified_at(): void
    {
        [$tenant, $user] = $this->createOrgTenantWithMfaRequiredMember();
        $this->assignDashboardViewToUser($user, $tenant);

        $state = SsoAuthorizationState::encode(SsoAuthorizationState::mint($tenant->id));

        $this->mock(SsoAuthService::class, function ($mock) use ($user) {
            $mock->shouldReceive('completeCallback')
                ->once()
                ->andReturn(SsoIdentityResolutionResult::success($user, new TenantUserIdentity, false));
        });

        $controller = file_get_contents(base_path('Modules/Identity/app/Http/Controllers/SsoAuthController.php'));
        $this->assertStringNotContainsString('mfa_verified_at', $controller);

        $this->get('/auth/sso/callback?code=abc&state='.urlencode($state))
            ->assertRedirect($this->tenantDashboardRedirectUri($tenant));

        $this->assertAuthenticatedAs($user, 'web');
        $this->assertNull(session('mfa_verified_at'));
    }

    #[Test]
    public function mfa_required_tenant_redirects_to_challenge_after_sso_login(): void
    {
        [$tenant, $user] = $this->createOrgTenantWithMfaRequiredMember();
        $this->assignDashboardViewToUser($user, $tenant);

        $state = SsoAuthorizationState::encode(SsoAuthorizationState::mint($tenant->id));

        $this->mock(SsoAuthService::class, function ($mock) use ($user) {
            $mock->shouldReceive('completeCallback')
                ->once()
                ->andReturn(SsoIdentityResolutionResult::success($user, new TenantUserIdentity, false));
        });

        $this->get('/auth/sso/callback?code=abc&state='.urlencode($state))
            ->assertRedirect($this->tenantDashboardRedirectUri($tenant));

        $this->get('/t/'.$tenant->id.'/dashboard')
            ->assertRedirect(route('identity.mfa.challenge', ['tenant' => $tenant->entryKey()]));
    }

    protected function grantSsoAndMfaEntitlements(Tenant $tenant, bool $required = false): void
    {
        $plan = Plan::query()->firstOrCreate(
            ['code' => 'sso-mfa-stepup'],
            ['name' => 'SSO MFA Step-up', 'is_active' => true]
        );

        foreach (['sso_available', 'mfa_available'] as $code) {
            Entitlement::query()->firstOrCreate(
                ['plan_id' => $plan->id, 'code' => $code],
                ['name' => ucwords(str_replace('_', ' ', $code)), 'is_active' => true]
            );
        }

        if ($required) {
            Entitlement::query()->firstOrCreate(
                ['plan_id' => $plan->id, 'code' => 'mfa_required'],
                ['name' => 'MFA Required', 'is_active' => true]
            );
        }

        Subscription::query()->updateOrCreate(
            ['tenant_id' => $tenant->id, 'status' => 'active'],
            [
                'id' => Str::uuid()->toString(),
                'plan_id' => $plan->id,
                'starts_at' => now(),
            ]
        );

        if ($required) {
            app(SecurityPolicyService::class)->update($tenant, ['mfa_required' => true]);
        }
    }
}
