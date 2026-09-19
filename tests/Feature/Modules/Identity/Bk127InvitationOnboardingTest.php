<?php

namespace Tests\Feature\Modules\Identity;

use App\Http\Auth\TenantEntryUrlResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Modules\Identity\Http\Controllers\InvitationAcceptController;
use Modules\Identity\Models\TenantInvitation;
use Modules\Identity\Models\TenantUser;
use Modules\Identity\Services\TenantInvitationService;
use Modules\Tenancy\Models\Tenant;
use Modules\Tenancy\Services\TenantDomainProvisioner;
use Tests\Support\InteractsWithTenantAddressingProfile;
use Tests\TestCase;

/**
 * BK-127 D1 — invitation activation public onboarding + completion → tenant login (#25).
 */
class Bk127InvitationOnboardingTest extends TestCase
{
    use InteractsWithTenantAddressingProfile;
    use RefreshDatabase;

    protected TenantUser $owner;

    protected Tenant $tenant;

    protected function setUp(): void
    {
        $this->forceAddressingEnv('path_host');
        parent::setUp();
        Mail::fake();
        $this->owner = TenantUser::factory()->create();
        $this->tenant = $this->createPersonalTenant($this->owner);
    }

    public function test_accept_vue_uses_public_onboarding_shell_not_app_or_guest_layout(): void
    {
        $src = file_get_contents(resource_path('js/Pages/Invitations/Accept.vue'));
        $this->assertNotFalse($src);
        $this->assertStringContainsString('PublicOnboardingShell', $src);
        $this->assertStringContainsString('PasswordPairFields', $src);
        $this->assertStringNotContainsString("from '@/Layouts/GuestLayout.vue'", $src);
        $this->assertStringNotContainsString("from '@/Layouts/AppLayout.vue'", $src);
        $this->assertStringNotContainsString('AppLayout', $src);
    }

    public function test_register_vue_shares_public_onboarding_shell(): void
    {
        $src = file_get_contents(resource_path('js/Pages/Auth/Register.vue'));
        $this->assertNotFalse($src);
        $this->assertStringContainsString('PublicOnboardingShell', $src);
        $this->assertStringContainsString('PasswordPairFields', $src);
        $this->assertStringContainsString('registerFormFeedback', $src);
    }

    public function test_guest_invitation_show_is_public_activation_without_auth(): void
    {
        $service = app(TenantInvitationService::class);
        $email = 'onboard-'.uniqid().'@example.com';
        $created = $service->createUserAndInvite($this->tenant, 'Yaser', 'Hadi', $email, $this->owner);

        $this->get('/invitations/'.$created['plainToken'])
            ->assertRedirect(route('invitations.show'));

        $this->assertFalse(Auth::check());

        $this->withSession([
            InvitationAcceptController::SESSION_INVITATION_ID_KEY => $created['invitation']->id,
        ])
            ->get(route('invitations.show'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Invitations/Accept')
                ->where('email', $email)
                ->where('isAuthenticated', false)
                ->where('invitationTenant.id', (string) $this->tenant->id)
                ->where('invitationTenant.name', $this->tenant->name)
                ->where('lifecycleMessage', null)
                ->missing('tenant'));

        $this->assertFalse(Auth::check());
    }

    public function test_create_account_activates_without_login_and_redirects_to_tenant_login(): void
    {
        app(TenantDomainProvisioner::class)->ensurePlatformSubdomain($this->tenant);

        $service = app(TenantInvitationService::class);
        $email = 'activate-'.uniqid().'@example.com';
        $password = 'SecurePass1!';
        $created = $service->createUserAndInvite($this->tenant, 'Yaser', 'Hadi', $email, $this->owner);

        $expectedLogin = app(TenantEntryUrlResolver::class)->loginUrl($this->tenant);
        $this->assertStringContainsString('/login', $expectedLogin);
        $this->assertStringContainsString($this->tenant->slug.'.jabal.test', $expectedLogin);

        $this->withServerVariables(['HTTP_HOST' => 'jabal.test'])
            ->get('http://jabal.test/invitations/'.$created['plainToken'])
            ->assertRedirect('http://jabal.test/invitations/accept');

        $response = $this->withServerVariables(['HTTP_HOST' => 'jabal.test'])
            ->post('http://jabal.test/invitations/register', [
                'password' => $password,
                'password_confirmation' => $password,
            ]);

        // Non-Inertia clients get a normal redirect; Inertia XHR gets 409 + X-Inertia-Location.
        $this->assertContains($response->status(), [302, 303, 409]);
        $location = $response->headers->get('X-Inertia-Location')
            ?: $response->headers->get('Location');
        $this->assertNotNull($location);
        $this->assertStringStartsWith(rtrim($expectedLogin, '/'), explode('?', $location)[0]);
        $this->assertStringContainsString('account=activated', $location);

        $this->assertFalse(Auth::check());
        $this->assertGuest();

        $user = TenantUser::withoutGlobalScope('tenant')->whereKey($created['user']->id)->first();
        $this->assertNotNull($user);
        $this->assertTrue(Hash::check($password, $user->password));

        $invitation = TenantInvitation::query()
            ->withoutGlobalScope('tenant')
            ->findOrFail($created['invitation']->id);
        $this->assertNotNull($invitation->accepted_at);
    }

    public function test_create_account_validation_failure_stays_on_activation_with_errors(): void
    {
        $service = app(TenantInvitationService::class);
        $created = $service->createUserAndInvite(
            $this->tenant,
            'Val',
            'Fail',
            'valfail-'.uniqid().'@example.com',
            $this->owner
        );

        $this->withServerVariables(['HTTP_HOST' => 'jabal.test'])
            ->get('http://jabal.test/invitations/'.$created['plainToken'])
            ->assertRedirect('http://jabal.test/invitations/accept');

        $response = $this->withServerVariables(['HTTP_HOST' => 'jabal.test'])
            ->from('http://jabal.test/invitations/accept')
            ->post('http://jabal.test/invitations/register', [
                'password' => 'short',
                'password_confirmation' => 'noshort',
            ]);

        // Must not leave activation via tenant-login location on validation failure.
        $this->assertNotSame(409, $response->status());
        $location = (string) ($response->headers->get('X-Inertia-Location') ?: $response->headers->get('Location') ?: '');
        $this->assertStringNotContainsString('account=activated', $location);
        $this->assertFalse(Auth::check());

        $invitation = TenantInvitation::query()
            ->withoutGlobalScope('tenant')
            ->findOrFail($created['invitation']->id);
        $this->assertNull($invitation->accepted_at);

        // ValidationException is raised with actionable password messages (shown via Inertia errors in UI).
        $this->assertNotNull($response->exception);
        $this->assertInstanceOf(\Illuminate\Validation\ValidationException::class, $response->exception);
        $this->assertArrayHasKey('password', $response->exception->errors());
    }
}
