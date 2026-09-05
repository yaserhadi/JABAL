<?php

namespace Tests\Feature\Modules\Identity;

use Modules\Identity\Models\TenantUser;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Modules\Identity\Exceptions\SsoSecurityException;
use Modules\Identity\Services\SecurityPolicyService;
use Modules\Identity\Services\SsoAuthService;
use Modules\Identity\Services\SsoOperationalExposureService;
use Modules\Identity\Support\Auth\AuthenticationLoginPolicy;
use Modules\Tenancy\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\GrantsSsoEntitlement;
use Tests\Support\InteractsWithTenantAddressingProfile;
use Tests\TestCase;

/**
 * WAVE-3 GAP-009: Authentication Policy (Password | SSO | Both).
 * Credential existence ≠ LOGIN permission.
 */
class AuthenticationLoginPolicyTest extends TestCase
{
    use GrantsSsoEntitlement;
    use InteractsWithTenantAddressingProfile;

    protected TenantUser $user;

    protected Tenant $tenant;

    protected function setUp(): void
    {
        $this->forceAddressingEnv('path');
        parent::setUp();

        $this->user = $this->registerTenantUser('Policy User', 'policy-'.uniqid().'@example.com');
        $this->tenant = $this->user->personalTenant();
        $this->grantSsoAvailable($this->tenant);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->restoreAddressingEnv();
    }

    protected function setAuthPolicy(string $mode): void
    {
        app(SecurityPolicyService::class)->update($this->tenant, [
            'authentication_policy' => $mode,
        ], bypassEnforcementGate: true);
    }

    #[Test]
    public function password_policy_permits_password_login(): void
    {
        $this->setAuthPolicy(AuthenticationLoginPolicy::PASSWORD);

        $this->assertTrue(app(AuthenticationLoginPolicy::class)->allowsPasswordLogin($this->tenant));

        $response = $this->post('/t/'.$this->tenant->id.'/login', [
            'email' => $this->user->email,
            'password' => 'password',
        ]);

        $response->assertSessionHasNoErrors();
        $this->assertAuthenticatedAs($this->user);
    }

    #[Test]
    public function password_policy_denies_sso_login(): void
    {
        $this->setAuthPolicy(AuthenticationLoginPolicy::PASSWORD);

        $this->assertFalse(app(AuthenticationLoginPolicy::class)->allowsSsoLogin($this->tenant));
        $this->assertFalse(app(SsoOperationalExposureService::class)->isExposedOnTenantLogin($this->tenant));

        $this->expectException(SsoSecurityException::class);
        app(SsoAuthService::class)->assertTenantMayStartSso($this->tenant);
    }

    #[Test]
    public function sso_policy_denies_password_login(): void
    {
        $this->setAuthPolicy(AuthenticationLoginPolicy::SSO);

        $this->assertFalse(app(AuthenticationLoginPolicy::class)->allowsPasswordLogin($this->tenant));

        $response = $this->from('/t/'.$this->tenant->id.'/login')
            ->post('/t/'.$this->tenant->id.'/login', [
                'email' => $this->user->email,
                'password' => 'password',
            ]);

        $response->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    #[Test]
    public function sso_policy_does_not_delete_password_credential(): void
    {
        $this->setAuthPolicy(AuthenticationLoginPolicy::SSO);

        tenancy()->initialize($this->tenant);
        $fresh = TenantUser::withoutGlobalScope('tenant')->findOrFail($this->user->id);
        $this->assertNotEmpty($fresh->password);
        $this->assertTrue(Hash::check('password', $fresh->password));
        tenancy()->end();

        // Policy mutation alone must not alter the hash.
        $hashBefore = $fresh->password;
        $this->setAuthPolicy(AuthenticationLoginPolicy::SSO);
        tenancy()->initialize($this->tenant);
        $this->assertSame($hashBefore, TenantUser::withoutGlobalScope('tenant')->findOrFail($this->user->id)->password);
        tenancy()->end();
    }

    #[Test]
    public function sso_policy_permits_sso_when_other_gates_would_run(): void
    {
        $this->setAuthPolicy(AuthenticationLoginPolicy::SSO);

        $this->assertTrue(app(AuthenticationLoginPolicy::class)->allowsSsoLogin($this->tenant));

        // Without operational SSO config, initiation still fails on operational gate — not by flipping to Password.
        try {
            app(SsoAuthService::class)->assertTenantMayStartSso($this->tenant);
            $this->fail('Expected operational/entitlement gate failure after policy allow.');
        } catch (SsoSecurityException $e) {
            $this->assertNotSame('sso_login_denied_by_authentication_policy', $e->getMessage());
        }

        $this->assertSame(
            AuthenticationLoginPolicy::SSO,
            app(AuthenticationLoginPolicy::class)->mode($this->tenant)
        );
    }

    #[Test]
    public function both_permits_password_and_sso_independently(): void
    {
        $this->setAuthPolicy(AuthenticationLoginPolicy::BOTH);

        $policy = app(AuthenticationLoginPolicy::class);
        $this->assertTrue($policy->allowsPasswordLogin($this->tenant));
        $this->assertTrue($policy->allowsSsoLogin($this->tenant));

        $this->post('/t/'.$this->tenant->id.'/login', [
            'email' => $this->user->email,
            'password' => 'password',
        ])->assertSessionHasNoErrors();
        $this->assertAuthenticatedAs($this->user);
    }

    #[Test]
    public function sso_failure_does_not_mutate_policy_or_enable_password_fallback(): void
    {
        $this->setAuthPolicy(AuthenticationLoginPolicy::SSO);

        try {
            app(SsoAuthService::class)->assertTenantMayStartSso($this->tenant);
        } catch (SsoSecurityException) {
            // expected (operational or policy path)
        }

        $this->assertSame(
            AuthenticationLoginPolicy::SSO,
            app(SecurityPolicyService::class)->getAuthenticationPolicy($this->tenant)
        );
        $this->assertFalse(app(AuthenticationLoginPolicy::class)->allowsPasswordLogin($this->tenant));

        $this->from('/t/'.$this->tenant->id.'/login')
            ->post('/t/'.$this->tenant->id.'/login', [
                'email' => $this->user->email,
                'password' => 'password',
            ])
            ->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    #[Test]
    public function policy_cannot_bypass_entitlement_operational_gate(): void
    {
        // Both allows SSO by policy, but no grant / operational config → still denied by gate.
        $this->setAuthPolicy(AuthenticationLoginPolicy::BOTH);

        $tenantNoEntitlement = Tenant::factory()->create([
            'status' => 'active',
            'slug' => 'noent-'.uniqid(),
        ]);

        $this->assertTrue(app(AuthenticationLoginPolicy::class)->allowsSsoLogin($tenantNoEntitlement));

        $this->expectException(SsoSecurityException::class);
        app(SsoAuthService::class)->assertTenantMayStartSso($tenantNoEntitlement);
    }

    #[Test]
    public function roles_unchanged_when_authentication_policy_updates(): void
    {
        $this->actingAsTenantUser($this->user, $this->tenant);
        $rolesBefore = $this->user->getRoleNames()->sort()->values()->all();

        $this->setAuthPolicy(AuthenticationLoginPolicy::PASSWORD);
        $this->setAuthPolicy(AuthenticationLoginPolicy::SSO);
        $this->setAuthPolicy(AuthenticationLoginPolicy::BOTH);

        $this->user->refresh();
        $this->assertSame($rolesBefore, $this->user->getRoleNames()->sort()->values()->all());
    }

    #[Test]
    public function login_page_keeps_password_form_available_under_sso_only_for_exception_recovery(): void
    {
        // WAVE-5: operational Password LOGIN remains denied by policy for normal Users,
        // but the login form stays available so Exception / temporary recovery Users can authenticate.
        $this->setAuthPolicy(AuthenticationLoginPolicy::SSO);

        $this->get('/t/'.$this->tenant->id.'/login')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Auth/TenantLogin')
                ->where('passwordLoginAllowed', true)
            );

        $this->assertFalse(
            app(AuthenticationLoginPolicy::class)->allowsPasswordLogin($this->tenant, $this->user)
        );
    }
}
