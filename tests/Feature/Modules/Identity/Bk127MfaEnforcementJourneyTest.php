<?php

namespace Tests\Feature\Modules\Identity;

use App\Models\Rbac\TenantPermission as Permission;
use App\Models\Rbac\TenantRole as Role;
use Illuminate\Support\Str;
use Modules\Billing\Models\Entitlement;
use Modules\Billing\Models\Plan;
use Modules\Billing\Models\Subscription;
use Modules\Identity\Models\TenantUser;
use Modules\Identity\Services\MfaService;
use Modules\Identity\Services\SecurityPolicyService;
use Modules\Tenancy\Models\Tenant;
use PragmaRX\Google2FA\Google2FA;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * BK-127 #32 — MFA policy administration ≠ enrollment ceremony;
 * enrollment QR from local otpauth (no external QR service).
 */
class Bk127MfaEnforcementJourneyTest extends TestCase
{
    protected TenantUser $admin;

    protected Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSecurityPolicyRbac();
        $this->admin = $this->registerTenantUser('MFA Admin', 'mfa-admin-'.uniqid().'@example.com');
        $this->tenant = $this->admin->personalTenant();
        $this->assignSecurityPolicyAdmin($this->admin, $this->tenant);
        $this->grantMfaAvailable($this->tenant);
    }

    public function test_enabling_mfa_required_stays_on_settings_without_hijacking_session(): void
    {
        $response = $this->actingAsTenantUser($this->admin, $this->tenant)
            ->from('/t/'.$this->tenant->id.'/security/settings')
            ->patch('/t/'.$this->tenant->id.'/security/settings/policies', [
                'mfa_required' => true,
            ]);

        $response->assertRedirect($this->tenantNamedRouteUrl('identity.security-settings.show', $this->tenant));
        $response->assertSessionHas('success', 'MFA requirement updated.');
        $this->assertFalse(
            str_contains($response->headers->get('Location') ?? '', '/security/mfa/enroll'),
            'Policy save must not redirect current session to enrollment'
        );

        $follow = $this->actingAsTenantUser($this->admin, $this->tenant)
            ->get('/t/'.$this->tenant->id.'/security/settings');

        $follow->assertOk();
        $follow->assertInertia(fn ($page) => $page
            ->component('SecuritySettings/Index')
            ->where('policies.mfa_required', true)
            ->where('mfa.policy_required', true)
            ->where('mfa.required', true)
            ->where('mfa.available', true)
            ->where('mfa.enrolled', false));

        // Existing authenticated session remains usable on protected tenant routes.
        $dash = $this->actingAsTenantUser($this->admin, $this->tenant)
            ->get('/t/'.$this->tenant->id.'/dashboard');
        $dash->assertOk();
    }

    public function test_next_login_unenrolled_user_is_forced_to_enrollment(): void
    {
        app(SecurityPolicyService::class)->update($this->tenant, ['mfa_required' => true]);

        $login = $this->post('/t/'.$this->tenant->id.'/login', [
            'email' => $this->admin->email,
            'password' => 'password',
        ]);

        $login->assertRedirect($this->tenantNamedRouteUrl('identity.mfa.enroll', $this->tenant));
        $login->assertSessionHas(MfaService::SESSION_POST_LOGIN_ENROLLMENT, true);
        $login->assertCookie(MfaService::COOKIE_POST_LOGIN_ENROLLMENT);

        // Explicit cookie on follow-up: proves middleware gate (HTTP test client may not
        // always re-send Set-Cookie from prior response under array sessions).
        $blocked = $this->withCookie(MfaService::COOKIE_POST_LOGIN_ENROLLMENT, '1')
            ->get('/t/'.$this->tenant->id.'/dashboard');
        $blocked->assertRedirect($this->tenantNamedRouteUrl('identity.mfa.enroll', $this->tenant));
    }

    public function test_enrollment_page_passes_otpauth_uri_and_secret(): void
    {
        app(SecurityPolicyService::class)->update($this->tenant, ['mfa_required' => true]);

        $this->post('/t/'.$this->tenant->id.'/login', [
            'email' => $this->admin->email,
            'password' => 'password',
        ]);

        $response = $this->get('/t/'.$this->tenant->id.'/security/mfa/enroll');
        $response->assertOk();
        $response->assertInertia(function ($page) {
            $page->component('Security/MfaEnroll')
                ->has('secret')
                ->has('otpauth_uri')
                ->has('qr_url');

            $props = $page->toArray()['props'] ?? [];
            $uri = (string) ($props['otpauth_uri'] ?? '');
            $this->assertStringStartsWith('otpauth://', $uri);
            $this->assertStringNotContainsString('api.qrserver.com', $uri);
            $this->assertSame($uri, (string) ($props['qr_url'] ?? ''));
            $this->assertNotSame('', (string) ($props['secret'] ?? ''));
        });

        $vue = file_get_contents(base_path('resources/js/Pages/Security/MfaEnroll.vue'));
        $this->assertStringContainsString("from 'qrcode'", $vue);
        $this->assertStringContainsString('never sent to an external qr service', strtolower($vue));
        $this->assertStringContainsString("Can't scan the code?", $vue);
        $this->assertStringContainsString('Enter text key instead', $vue);
        $this->assertStringContainsString('Hide key', $vue);
        $this->assertStringContainsString('showManualKey', $vue);
    }

    public function test_valid_totp_confirms_enrollment_then_enrolled_login_challenges(): void
    {
        app(SecurityPolicyService::class)->update($this->tenant, ['mfa_required' => true]);

        $this->post('/t/'.$this->tenant->id.'/login', [
            'email' => $this->admin->email,
            'password' => 'password',
        ]);

        $enrollPage = $this->get('/t/'.$this->tenant->id.'/security/mfa/enroll');
        $enrollPage->assertOk();
        $secret = null;
        $enrollPage->assertInertia(function ($page) use (&$secret) {
            $props = $page->toArray()['props'] ?? [];
            $secret = (string) ($props['secret'] ?? '');
        });
        $this->assertNotSame('', $secret);

        $code = (new Google2FA)->getCurrentOtp($secret);
        $confirm = $this->post('/t/'.$this->tenant->id.'/security/mfa/enroll', [
            'code' => $code,
        ]);
        $confirm->assertRedirect($this->tenantNamedRouteUrl('dashboard', $this->tenant));
        $this->assertFalse(session()->has(MfaService::SESSION_POST_LOGIN_ENROLLMENT));
        $this->assertTrue(app(MfaService::class)->userHasConfirmedMfa($this->admin->fresh()));

        // Next login for enrolled user → MFA challenge (not enrollment).
        $this->post('/t/'.$this->tenant->id.'/logout');

        $relogin = $this->post('/t/'.$this->tenant->id.'/login', [
            'email' => $this->admin->email,
            'password' => 'password',
        ]);
        $relogin->assertRedirect($this->tenantNamedRouteUrl('dashboard', $this->tenant));

        $challengeGate = $this->get('/t/'.$this->tenant->id.'/dashboard');
        $challengeGate->assertRedirect($this->tenantNamedRouteUrl('identity.mfa.challenge', $this->tenant));
    }

    protected function grantMfaAvailable(Tenant $tenant): void
    {
        $plan = Plan::query()->firstOrCreate(
            ['code' => 'mfa-journey-test'],
            ['name' => 'MFA Journey Test', 'is_active' => true]
        );

        Entitlement::query()->firstOrCreate(
            ['plan_id' => $plan->id, 'code' => 'mfa_available'],
            ['name' => 'MFA Available', 'is_active' => true]
        );

        Subscription::query()->updateOrCreate(
            ['tenant_id' => $tenant->id, 'status' => 'active'],
            [
                'id' => Str::uuid()->toString(),
                'plan_id' => $plan->id,
                'starts_at' => now(),
            ]
        );
    }

    protected function seedSecurityPolicyRbac(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $guard = config('auth.defaults.guard');
        foreach ([
            'tenant.security-policy.view',
            'tenant.security-policy.update',
            'dashboard.view',
            'workspace.view',
        ] as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => $guard]);
        }
    }

    protected function assignSecurityPolicyAdmin(TenantUser $user, Tenant $tenant): void
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->getTenantKey());
        $guard = config('auth.defaults.guard');
        $role = Role::firstOrCreate(
            ['name' => 'tenant-admin', 'guard_name' => $guard, 'tenant_id' => $tenant->id],
            ['name' => 'tenant-admin', 'guard_name' => $guard, 'tenant_id' => $tenant->id]
        );
        foreach (['tenant.security-policy.view', 'tenant.security-policy.update', 'dashboard.view', 'workspace.view'] as $perm) {
            $p = Permission::findByName($perm, $guard);
            if ($p && ! $role->hasPermissionTo($p)) {
                $role->givePermissionTo($p);
            }
        }
        if (! $user->hasRole($role)) {
            $user->assignRole($role);
        }
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    }
}
