<?php

use App\Http\Middleware\EnsureUserBelongsToTenant;
use App\Support\Tenancy\TenantAddressingProfile;
use App\Support\Tenancy\TenantRouteRegistrar;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Modules\Identity\Http\Controllers\AuthController;
use Modules\Identity\Http\Controllers\EnterpriseSsoBackChannelLogoutController;
use Modules\Identity\Http\Controllers\EnterpriseSsoCallbackController;
use Modules\Identity\Http\Controllers\EnterpriseSsoHandoffController;
use Modules\Identity\Http\Controllers\EnterpriseSsoInitiateController;
use Modules\Identity\Http\Controllers\EnterpriseSsoStartController;
use Modules\Identity\Http\Controllers\InvitationAcceptController;
use Modules\Identity\Http\Controllers\MfaController;
use Modules\Identity\Http\Controllers\SecurityPolicyController;
use Modules\Identity\Http\Controllers\AuthenticationAdministrationController;
use Modules\Identity\Http\Controllers\MandatorySsoEnrollmentController;
use Modules\Identity\Http\Controllers\SsoEnforcementAdministrationController;
use Modules\Identity\Http\Controllers\SecuritySettingsController;
use Modules\Identity\Http\Controllers\SsoAuthController;
use Modules\Identity\Http\Controllers\SsoConfigController;
use Modules\Identity\Http\Controllers\SsoGovernanceController;
use Modules\Identity\Http\Controllers\WorkforceSsoEnrollmentCompleteController;
use Modules\Identity\Http\Controllers\WorkforceSsoEnrollmentController;
use Modules\Identity\Http\Controllers\WorkforceSsoEnrollmentStepUpController;
use Modules\Identity\Http\Middleware\EnsureMandatorySsoEnrollment;
use Modules\Identity\Http\Middleware\EnsureMfaVerified;
use Modules\Identity\Http\Middleware\EnterpriseSsoTransitionHeaders;
use Modules\Identity\Http\Middleware\InvitationSecurityHeaders;

/*
|--------------------------------------------------------------------------
| Identity Module Web Routes (auth, dashboard) — Inertia + Vuetify
|--------------------------------------------------------------------------
|
| BK-073: Path profile keeps /t/{tenant}/… ; Host profile uses domain-bound
| routes (Central Route Authority Matrix). Route names stay unique per profile.
|
*/

$addressing = app(TenantAddressingProfile::class);
$registrar = app(TenantRouteRegistrar::class);

// Invitation accept (guest or authenticated) — shared both profiles
Route::middleware(['throttle:invitations', InvitationSecurityHeaders::class])->group(function () {
    Route::get('invitations/accept', [InvitationAcceptController::class, 'show'])->name('invitations.show');
    Route::get('invitations/{token}', [InvitationAcceptController::class, 'bootstrap'])
        ->where('token', '[A-Za-z0-9]{64}')
        ->name('invitations.bootstrap');

    Route::middleware('guest')->group(function () {
        Route::post('invitations/register', [InvitationAcceptController::class, 'registerAndAccept'])->name('invitations.register');
    });

    Route::middleware('auth')->group(function () {
        Route::post('invitations/accept', [InvitationAcceptController::class, 'accept'])->name('invitations.accept');
    });
});

Route::get('security/email-change/verify/{token}', [AuthenticationAdministrationController::class, 'verifyEmailChange'])
    ->where('token', '[A-Za-z0-9]{64}')
    ->middleware('throttle:60,1')
    ->name('auth-admin.email-change.verify');

if ($addressing->isHost()) {
    // Central Route Authority Matrix — Platform Host: discovery login/register + logout
    $registrar->onPlatformHost(function () {
        Route::middleware('guest')->group(function () {
            Route::get('login', [AuthController::class, 'showLogin'])->name('login');
            Route::post('login', [AuthController::class, 'login']);
            Route::get('register', [AuthController::class, 'showRegister'])->name('register');
            Route::post('register', [AuthController::class, 'register']);
        });

        Route::middleware('auth')->group(function () {
            Route::post('logout', [AuthController::class, 'logout'])->name('logout');
        });
    });

    // Auth Host ONLY — Enterprise SSO initiate (WS3) + callback (WS4)
    $registrar->onAuthHost(function () {
        Route::middleware(['web', 'guest', 'throttle:sso-enterprise-initiate', EnterpriseSsoTransitionHeaders::class])->group(function () {
            Route::get('auth/enterprise-sso/initiate', EnterpriseSsoInitiateController::class)
                ->name('identity.enterprise-sso.initiate');
        });
        Route::middleware(['web', 'guest', 'throttle:sso-enterprise-callback', EnterpriseSsoTransitionHeaders::class])->group(function () {
            Route::match(['get', 'post'], 'auth/enterprise-sso/callback', EnterpriseSsoCallbackController::class)
                ->name('identity.enterprise-sso.callback');
        });
        // Back-Channel Logout: no session cookie dependency; Auth Host only.
        Route::middleware(['throttle:sso-enterprise-bclogout', EnterpriseSsoTransitionHeaders::class])->group(function () {
            Route::post('auth/enterprise-sso/backchannel-logout', EnterpriseSsoBackChannelLogoutController::class)
                ->name('identity.enterprise-sso.backchannel-logout');
        });
        // BK-103: Path-era identity.sso.callback is not registered on Host (absence ⇒ 404).
    });

    // Tenant Host — wildcard {tenant_label} is NOT a resolver
    $registrar->onTenantHost(function () {
        Route::middleware(['web', 'guest', 'throttle:sso-enterprise-start', EnterpriseSsoTransitionHeaders::class])->group(function () {
            Route::get('/auth/enterprise-sso/start', EnterpriseSsoStartController::class)
                ->name('identity.enterprise-sso.start');
        });

        Route::middleware(['web', 'throttle:sso-enterprise-handoff', EnterpriseSsoTransitionHeaders::class])->group(function () {
            Route::get('/auth/enterprise-sso/handoff', EnterpriseSsoHandoffController::class)
                ->name('identity.enterprise-sso.handoff');
        });

        Route::middleware(['web', 'guest'])->group(function () {
            Route::get('/login', [AuthController::class, 'showTenantLogin'])->name('tenant.login');
            Route::post('/login', [AuthController::class, 'tenantLogin'])->name('tenant.login.submit');
            // BK-103: Path-era identity.sso.redirect is not registered on Host (absence ⇒ 404).
        });

        // BK-099: invitation open (guest or auth) — local auth gate
        // Param name enrollment_token avoids collision with domain/other {token} bindings.
        Route::middleware(['web', 'throttle:60,1'])->group(function () {
            Route::get('/security/sso/enrollment/invitations/{enrollment_token}', [WorkforceSsoEnrollmentController::class, 'openInvitation'])
                ->where('enrollment_token', '[A-Za-z0-9]{64}')
                ->name('identity.sso.enrollment.invitation');
        });

        Route::middleware([
            'web',
            'auth',
            'throttle:sso-enterprise-mfa',
            EnsureUserBelongsToTenant::class,
            EnterpriseSsoTransitionHeaders::class,
        ])->group(function () {
            Route::get('/security/mfa/enroll', [MfaController::class, 'showEnroll'])->name('identity.mfa.enroll');
            Route::post('/security/mfa/enroll', [MfaController::class, 'confirmEnroll'])->name('identity.mfa.enroll.confirm');
            Route::get('/security/mfa/challenge', [MfaController::class, 'showChallenge'])->name('identity.mfa.challenge');
            Route::post('/security/mfa/challenge', [MfaController::class, 'verifyChallenge'])->name('identity.mfa.challenge.verify');
            Route::post('/logout', [AuthController::class, 'logout'])->name('tenant.logout');

            // BK-099: post-login resume + start OIDC + enrollment complete
            Route::get('/security/sso/enrollment/resume', [WorkforceSsoEnrollmentController::class, 'resume'])
                ->name('identity.sso.enrollment.resume');
            Route::post('/security/sso/enrollment/start', [WorkforceSsoEnrollmentController::class, 'start'])
                ->middleware('throttle:sso-enterprise-start')
                ->name('identity.sso.enrollment.start');
            Route::get('/auth/enterprise-sso/enrollment/complete', WorkforceSsoEnrollmentCompleteController::class)
                ->middleware(['throttle:sso-enterprise-handoff', EnterpriseSsoTransitionHeaders::class])
                ->name('identity.sso.enrollment.complete');
            Route::get('/security/sso/enrollment/step-up/password', [WorkforceSsoEnrollmentStepUpController::class, 'showPassword'])
                ->name('identity.sso.enrollment.step-up.password.show');
            Route::post('/security/sso/enrollment/step-up/password', [WorkforceSsoEnrollmentStepUpController::class, 'confirmPassword'])
                ->name('identity.sso.enrollment.step-up.password');

            // WAVE-5: Mandatory Enrollment page (outside application gate)
            Route::get('/security/sso/mandatory-enrollment', [MandatorySsoEnrollmentController::class, 'show'])
                ->name('identity.sso.mandatory-enrollment');
        });

        Route::middleware([
            'web',
            'auth',
            EnsureUserBelongsToTenant::class,
            EnsureMfaVerified::class,
            EnsureMandatorySsoEnrollment::class,
        ])->group(function () {
            Route::get('/dashboard', function () {
                $tenant = tenancy()->tenant;

                return Inertia::render('Dashboard', [
                    'tenant' => $tenant ? \App\Http\Auth\TenantInertiaProps::from($tenant) : null,
                ]);
            })->middleware('permission:dashboard.view')->name('dashboard');

            Route::get('/members', [\Modules\Tenancy\Http\Controllers\TenantMemberController::class, 'index'])
                ->middleware('permission:member.view')
                ->name('members.index');
            Route::patch('/members/{user}', [\Modules\Tenancy\Http\Controllers\TenantMemberController::class, 'updateRole'])
                ->middleware('permission:member.assign-role')
                ->name('members.update-role');
            Route::post('/members/{user}/suspend', [\Modules\Tenancy\Http\Controllers\TenantMemberController::class, 'suspend'])
                ->middleware('permission:member.suspend')
                ->name('members.suspend');
            Route::post('/members/{user}/activate', [\Modules\Tenancy\Http\Controllers\TenantMemberController::class, 'activate'])
                ->middleware('permission:member.suspend')
                ->name('members.activate');
            Route::post('/members/invite', [\Modules\Tenancy\Http\Controllers\TenantMemberController::class, 'invite'])
                ->middleware('permission:member.invite')
                ->name('members.invite');
            Route::post('/members/invite-existing', [\Modules\Tenancy\Http\Controllers\TenantMemberController::class, 'inviteExisting'])
                ->middleware('permission:member.invite')
                ->name('members.invite-existing');
            Route::post('/members/invitations/{invitation}/resend', [\Modules\Tenancy\Http\Controllers\TenantMemberController::class, 'resendInvitation'])
                ->middleware('permission:member.invite')
                ->name('members.resend-invitation');
            Route::delete('/members/invitations/{invitation}', [\Modules\Tenancy\Http\Controllers\TenantMemberController::class, 'revokeInvitation'])
                ->middleware('permission:member.invite')
                ->name('members.revoke-invitation');
            Route::delete('/members/{user}', [\Modules\Tenancy\Http\Controllers\TenantMemberController::class, 'remove'])
                ->middleware('permission:member.remove')
                ->name('members.remove');
            Route::post('/members/{user}/restore', [\Modules\Tenancy\Http\Controllers\TenantMemberController::class, 'restore'])
                ->middleware('permission:member.remove')
                ->name('members.restore');
            Route::delete('/members/{user}/permanent', [\Modules\Tenancy\Http\Controllers\TenantMemberController::class, 'deleteForever'])
                ->middleware('permission:member.remove')
                ->name('members.delete-forever');
            Route::post('/members/{user}/transfer-ownership', [\Modules\Tenancy\Http\Controllers\TenantMemberController::class, 'transferOwnership'])
                ->name('members.transfer-ownership');

            Route::get('/security/policies', [SecurityPolicyController::class, 'show'])
                ->name('identity.security-policies.show');
            Route::patch('/security/policies', [SecurityPolicyController::class, 'update'])
                ->name('identity.security-policies.update');

            Route::get('/security/sso', [SsoConfigController::class, 'show'])
                ->name('identity.sso.show');
            Route::patch('/security/sso', [SsoConfigController::class, 'update'])
                ->name('identity.sso.update');

            // BK-099 Workforce SSO enrollment admin
            Route::get('/security/sso/enrollments', [WorkforceSsoEnrollmentController::class, 'index'])
                ->name('identity.sso.enrollments.index');
            Route::post('/security/sso/enrollments', [WorkforceSsoEnrollmentController::class, 'store'])
                ->name('identity.sso.enrollments.store');
            Route::delete('/security/sso/enrollments/{invitationId}', [WorkforceSsoEnrollmentController::class, 'destroy'])
                ->name('identity.sso.enrollments.destroy');

            Route::post('/security/sso/versions/{versionId}/validate', [SsoGovernanceController::class, 'validateVersion'])
                ->name('identity.sso.versions.validate');
            Route::post('/security/sso/versions/{versionId}/test-only', [SsoGovernanceController::class, 'markTestOnly'])
                ->name('identity.sso.versions.test-only');
            Route::post('/security/sso/versions/{versionId}/approve', [SsoGovernanceController::class, 'approveVersion'])
                ->name('identity.sso.versions.approve');
            Route::post('/security/sso/versions/{versionId}/activate', [SsoGovernanceController::class, 'activateVersion'])
                ->name('identity.sso.versions.activate');
            Route::post('/security/sso/versions/{versionId}/disable', [SsoGovernanceController::class, 'disableVersion'])
                ->name('identity.sso.versions.disable');
            Route::post('/security/sso/versions/{versionId}/revoke-secret', [SsoGovernanceController::class, 'revokeSecret'])
                ->name('identity.sso.versions.revoke-secret');
            Route::post('/security/sso/versions/{versionId}/recover', [SsoGovernanceController::class, 'recover'])
                ->name('identity.sso.versions.recover');
            Route::post('/security/sso/rollout', [SsoGovernanceController::class, 'setRollout'])
                ->name('identity.sso.rollout');
            Route::post('/security/sso/kill-switch/pause-tenant', [SsoGovernanceController::class, 'pauseTenant'])
                ->name('identity.sso.kill-switch.pause-tenant');
            Route::post('/security/sso/kill-switch/security-disable', [SsoGovernanceController::class, 'securityDisable'])
                ->name('identity.sso.kill-switch.security-disable');
            Route::post('/security/sso/kill-switch/pause-platform', [SsoGovernanceController::class, 'pausePlatform'])
                ->name('identity.sso.kill-switch.pause-platform');
            Route::post('/security/sso/kill-switch/disable-platform', [SsoGovernanceController::class, 'disablePlatform'])
                ->name('identity.sso.kill-switch.disable-platform');

            Route::get('/security/settings', [SecuritySettingsController::class, 'show'])
                ->name('identity.security-settings.show');
            Route::patch('/security/settings/policies', [SecuritySettingsController::class, 'updatePolicies'])
                ->middleware('permission:tenant.security-policy.update')
                ->name('identity.security-settings.update-policies');
            Route::delete('/security/settings/sessions/{session}', [SecuritySettingsController::class, 'revokeSession'])
                ->name('identity.security-settings.revoke-session');
            Route::delete('/security/settings/sessions', [SecuritySettingsController::class, 'revokeOtherSessions'])
                ->name('identity.security-settings.revoke-other-sessions');

            Route::get('/security/auth-admin', [AuthenticationAdministrationController::class, 'index'])
                ->middleware('permission:'.\Modules\Identity\Support\Auth\AuthenticationAdministrationGate::PERMISSION)
                ->name('auth-admin.index');
            Route::post('/security/auth-admin/confirm-password', [AuthenticationAdministrationController::class, 'confirmPassword'])
                ->middleware('permission:'.\Modules\Identity\Support\Auth\AuthenticationAdministrationGate::PERMISSION)
                ->name('auth-admin.confirm-password');
            Route::post('/security/auth-admin/reset-password', [AuthenticationAdministrationController::class, 'resetPassword'])
                ->middleware('permission:'.\Modules\Identity\Support\Auth\AuthenticationAdministrationGate::PERMISSION)
                ->name('auth-admin.reset-password');
            Route::post('/security/auth-admin/reset-mfa', [AuthenticationAdministrationController::class, 'resetMfa'])
                ->middleware('permission:'.\Modules\Identity\Support\Auth\AuthenticationAdministrationGate::PERMISSION)
                ->name('auth-admin.reset-mfa');
            Route::post('/security/auth-admin/reset-sso', [AuthenticationAdministrationController::class, 'resetSso'])
                ->middleware('permission:'.\Modules\Identity\Support\Auth\AuthenticationAdministrationGate::PERMISSION)
                ->name('auth-admin.reset-sso');
            Route::post('/security/auth-admin/change-policy', [AuthenticationAdministrationController::class, 'changePolicy'])
                ->middleware('permission:'.\Modules\Identity\Support\Auth\AuthenticationAdministrationGate::PERMISSION)
                ->name('auth-admin.change-policy');
            Route::get('/security/sso-enforcement', [SsoEnforcementAdministrationController::class, 'index'])
                ->middleware('permission:'.\Modules\Identity\Support\Auth\AuthenticationAdministrationGate::PERMISSION)
                ->name('sso-enforcement.index');
            Route::post('/security/sso-enforcement/settings', [SsoEnforcementAdministrationController::class, 'updateSettings'])
                ->middleware('permission:'.\Modules\Identity\Support\Auth\AuthenticationAdministrationGate::PERMISSION)
                ->name('sso-enforcement.settings');
            Route::post('/security/sso-enforcement/exceptions', [SsoEnforcementAdministrationController::class, 'storeException'])
                ->middleware('permission:'.\Modules\Identity\Support\Auth\AuthenticationAdministrationGate::PERMISSION)
                ->name('sso-enforcement.exceptions.store');
            Route::post('/security/sso-enforcement/exceptions/{exceptionId}/revoke', [SsoEnforcementAdministrationController::class, 'revokeException'])
                ->middleware('permission:'.\Modules\Identity\Support\Auth\AuthenticationAdministrationGate::PERMISSION)
                ->name('sso-enforcement.exceptions.revoke');
            Route::post('/security/auth-admin/change-email', [AuthenticationAdministrationController::class, 'changeEmail'])
                ->middleware('permission:'.\Modules\Identity\Support\Auth\AuthenticationAdministrationGate::PERMISSION)
                ->name('auth-admin.change-email');
            Route::post('/security/auth-admin/path-a', [AuthenticationAdministrationController::class, 'startPathA'])
                ->middleware('permission:'.\Modules\Identity\Support\Auth\AuthenticationAdministrationGate::PERMISSION)
                ->name('auth-admin.path-a');
            Route::post('/security/auth-admin/path-b', [AuthenticationAdministrationController::class, 'activatePathB'])
                ->middleware('permission:'.\Modules\Identity\Support\Auth\AuthenticationAdministrationGate::PERMISSION)
                ->name('auth-admin.path-b');
        });
    });

    return;
}

// -------------------------------------------------------------------------
// Path profile (Profile B) — existing /t/{tenant} surfaces
// -------------------------------------------------------------------------

Route::middleware('guest')->group(function () {
    Route::get('login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('login', [AuthController::class, 'login']);
    Route::get('register', [AuthController::class, 'showRegister'])->name('register');
    Route::post('register', [AuthController::class, 'register']);
});

// BK-097: callback must be reachable while authenticated so D12 ordinary session
// gates can deny different-user / wrong-binding without principal replacement.
Route::get('auth/sso/callback', [SsoAuthController::class, 'callback'])
    ->name('identity.sso.callback');

Route::prefix('t/{tenant}')
    ->middleware(['web', 'guest'])
    ->group(function () {
        Route::get('/login', [AuthController::class, 'showTenantLogin'])->name('tenant.login');
        Route::post('/login', [AuthController::class, 'tenantLogin'])->name('tenant.login.submit');
        Route::get('/auth/sso/redirect', [SsoAuthController::class, 'redirect'])
            ->name('identity.sso.redirect');
    });

Route::middleware('auth')->group(function () {
    Route::post('logout', [AuthController::class, 'logout'])->name('logout');
});

Route::prefix('t/{tenant}')
    ->middleware([
        'web',
        'auth',
        EnsureUserBelongsToTenant::class,
    ])
    ->group(function () {
        Route::get('/security/mfa/enroll', [MfaController::class, 'showEnroll'])->name('identity.mfa.enroll');
        Route::post('/security/mfa/enroll', [MfaController::class, 'confirmEnroll'])->name('identity.mfa.enroll.confirm');
        Route::get('/security/mfa/challenge', [MfaController::class, 'showChallenge'])->name('identity.mfa.challenge');
        Route::post('/security/mfa/challenge', [MfaController::class, 'verifyChallenge'])->name('identity.mfa.challenge.verify');
        Route::get('/security/sso/mandatory-enrollment', [MandatorySsoEnrollmentController::class, 'show'])
            ->name('identity.sso.mandatory-enrollment');
    });

Route::prefix('t/{tenant}')
    ->middleware([
        'web',
        'auth',
        EnsureUserBelongsToTenant::class,
        EnsureMfaVerified::class,
        EnsureMandatorySsoEnrollment::class,
    ])
    ->group(function () {
        Route::get('/dashboard', function () {
            $tenant = tenancy()->tenant;

            return Inertia::render('Dashboard', [
                'tenant' => $tenant ? \App\Http\Auth\TenantInertiaProps::from($tenant) : null,
            ]);
        })->middleware('permission:dashboard.view')->name('dashboard');

        Route::get('/members', [\Modules\Tenancy\Http\Controllers\TenantMemberController::class, 'index'])
            ->middleware('permission:member.view')
            ->name('members.index');
        Route::patch('/members/{user}', [\Modules\Tenancy\Http\Controllers\TenantMemberController::class, 'updateRole'])
            ->middleware('permission:member.assign-role')
            ->name('members.update-role');
        Route::post('/members/{user}/suspend', [\Modules\Tenancy\Http\Controllers\TenantMemberController::class, 'suspend'])
            ->middleware('permission:member.suspend')
            ->name('members.suspend');
        Route::post('/members/{user}/activate', [\Modules\Tenancy\Http\Controllers\TenantMemberController::class, 'activate'])
            ->middleware('permission:member.suspend')
            ->name('members.activate');
        Route::post('/members/invite', [\Modules\Tenancy\Http\Controllers\TenantMemberController::class, 'invite'])
            ->middleware('permission:member.invite')
            ->name('members.invite');
        Route::post('/members/invite-existing', [\Modules\Tenancy\Http\Controllers\TenantMemberController::class, 'inviteExisting'])
            ->middleware('permission:member.invite')
            ->name('members.invite-existing');
        Route::post('/members/invitations/{invitation}/resend', [\Modules\Tenancy\Http\Controllers\TenantMemberController::class, 'resendInvitation'])
            ->middleware('permission:member.invite')
            ->name('members.resend-invitation');
        Route::delete('/members/invitations/{invitation}', [\Modules\Tenancy\Http\Controllers\TenantMemberController::class, 'revokeInvitation'])
            ->middleware('permission:member.invite')
            ->name('members.revoke-invitation');
        Route::delete('/members/{user}', [\Modules\Tenancy\Http\Controllers\TenantMemberController::class, 'remove'])
            ->middleware('permission:member.remove')
            ->name('members.remove');
        Route::post('/members/{user}/restore', [\Modules\Tenancy\Http\Controllers\TenantMemberController::class, 'restore'])
            ->middleware('permission:member.remove')
            ->name('members.restore');
        Route::delete('/members/{user}/permanent', [\Modules\Tenancy\Http\Controllers\TenantMemberController::class, 'deleteForever'])
            ->middleware('permission:member.remove')
            ->name('members.delete-forever');
        Route::post('/members/{user}/transfer-ownership', [\Modules\Tenancy\Http\Controllers\TenantMemberController::class, 'transferOwnership'])
            ->name('members.transfer-ownership');

        Route::get('/security/policies', [SecurityPolicyController::class, 'show'])
            ->name('identity.security-policies.show');
        Route::patch('/security/policies', [SecurityPolicyController::class, 'update'])
            ->name('identity.security-policies.update');

        Route::get('/security/sso', [SsoConfigController::class, 'show'])
            ->name('identity.sso.show');
        Route::patch('/security/sso', [SsoConfigController::class, 'update'])
            ->name('identity.sso.update');

        Route::post('/security/sso/versions/{versionId}/validate', [SsoGovernanceController::class, 'validateVersion'])
            ->name('identity.sso.versions.validate');
        Route::post('/security/sso/versions/{versionId}/test-only', [SsoGovernanceController::class, 'markTestOnly'])
            ->name('identity.sso.versions.test-only');
        Route::post('/security/sso/versions/{versionId}/approve', [SsoGovernanceController::class, 'approveVersion'])
            ->name('identity.sso.versions.approve');
        Route::post('/security/sso/versions/{versionId}/activate', [SsoGovernanceController::class, 'activateVersion'])
            ->name('identity.sso.versions.activate');
        Route::post('/security/sso/versions/{versionId}/disable', [SsoGovernanceController::class, 'disableVersion'])
            ->name('identity.sso.versions.disable');
        Route::post('/security/sso/versions/{versionId}/revoke-secret', [SsoGovernanceController::class, 'revokeSecret'])
            ->name('identity.sso.versions.revoke-secret');
        Route::post('/security/sso/versions/{versionId}/recover', [SsoGovernanceController::class, 'recover'])
            ->name('identity.sso.versions.recover');
        Route::post('/security/sso/rollout', [SsoGovernanceController::class, 'setRollout'])
            ->name('identity.sso.rollout');
        Route::post('/security/sso/kill-switch/pause-tenant', [SsoGovernanceController::class, 'pauseTenant'])
            ->name('identity.sso.kill-switch.pause-tenant');
        Route::post('/security/sso/kill-switch/security-disable', [SsoGovernanceController::class, 'securityDisable'])
            ->name('identity.sso.kill-switch.security-disable');
        Route::post('/security/sso/kill-switch/pause-platform', [SsoGovernanceController::class, 'pausePlatform'])
            ->name('identity.sso.kill-switch.pause-platform');
        Route::post('/security/sso/kill-switch/disable-platform', [SsoGovernanceController::class, 'disablePlatform'])
            ->name('identity.sso.kill-switch.disable-platform');

        Route::get('/security/settings', [SecuritySettingsController::class, 'show'])
            ->name('identity.security-settings.show');
        Route::patch('/security/settings/policies', [SecuritySettingsController::class, 'updatePolicies'])
            ->middleware('permission:tenant.security-policy.update')
            ->name('identity.security-settings.update-policies');
        Route::delete('/security/settings/sessions/{session}', [SecuritySettingsController::class, 'revokeSession'])
            ->name('identity.security-settings.revoke-session');
        Route::delete('/security/settings/sessions', [SecuritySettingsController::class, 'revokeOtherSessions'])
            ->name('identity.security-settings.revoke-other-sessions');

        Route::get('/security/auth-admin', [AuthenticationAdministrationController::class, 'index'])
            ->middleware('permission:'.\Modules\Identity\Support\Auth\AuthenticationAdministrationGate::PERMISSION)
            ->name('auth-admin.index');
        Route::post('/security/auth-admin/confirm-password', [AuthenticationAdministrationController::class, 'confirmPassword'])
            ->middleware('permission:'.\Modules\Identity\Support\Auth\AuthenticationAdministrationGate::PERMISSION)
            ->name('auth-admin.confirm-password');
        Route::post('/security/auth-admin/reset-password', [AuthenticationAdministrationController::class, 'resetPassword'])
            ->middleware('permission:'.\Modules\Identity\Support\Auth\AuthenticationAdministrationGate::PERMISSION)
            ->name('auth-admin.reset-password');
        Route::post('/security/auth-admin/reset-mfa', [AuthenticationAdministrationController::class, 'resetMfa'])
            ->middleware('permission:'.\Modules\Identity\Support\Auth\AuthenticationAdministrationGate::PERMISSION)
            ->name('auth-admin.reset-mfa');
        Route::post('/security/auth-admin/reset-sso', [AuthenticationAdministrationController::class, 'resetSso'])
            ->middleware('permission:'.\Modules\Identity\Support\Auth\AuthenticationAdministrationGate::PERMISSION)
            ->name('auth-admin.reset-sso');
        Route::post('/security/auth-admin/change-policy', [AuthenticationAdministrationController::class, 'changePolicy'])
            ->middleware('permission:'.\Modules\Identity\Support\Auth\AuthenticationAdministrationGate::PERMISSION)
            ->name('auth-admin.change-policy');
        Route::get('/security/sso-enforcement', [SsoEnforcementAdministrationController::class, 'index'])
            ->middleware('permission:'.\Modules\Identity\Support\Auth\AuthenticationAdministrationGate::PERMISSION)
            ->name('sso-enforcement.index');
        Route::post('/security/sso-enforcement/settings', [SsoEnforcementAdministrationController::class, 'updateSettings'])
            ->middleware('permission:'.\Modules\Identity\Support\Auth\AuthenticationAdministrationGate::PERMISSION)
            ->name('sso-enforcement.settings');
        Route::post('/security/sso-enforcement/exceptions', [SsoEnforcementAdministrationController::class, 'storeException'])
            ->middleware('permission:'.\Modules\Identity\Support\Auth\AuthenticationAdministrationGate::PERMISSION)
            ->name('sso-enforcement.exceptions.store');
        Route::post('/security/sso-enforcement/exceptions/{exceptionId}/revoke', [SsoEnforcementAdministrationController::class, 'revokeException'])
            ->middleware('permission:'.\Modules\Identity\Support\Auth\AuthenticationAdministrationGate::PERMISSION)
            ->name('sso-enforcement.exceptions.revoke');
        Route::post('/security/auth-admin/change-email', [AuthenticationAdministrationController::class, 'changeEmail'])
            ->middleware('permission:'.\Modules\Identity\Support\Auth\AuthenticationAdministrationGate::PERMISSION)
            ->name('auth-admin.change-email');
        Route::post('/security/auth-admin/path-a', [AuthenticationAdministrationController::class, 'startPathA'])
            ->middleware('permission:'.\Modules\Identity\Support\Auth\AuthenticationAdministrationGate::PERMISSION)
            ->name('auth-admin.path-a');
        Route::post('/security/auth-admin/path-b', [AuthenticationAdministrationController::class, 'activatePathB'])
            ->middleware('permission:'.\Modules\Identity\Support\Auth\AuthenticationAdministrationGate::PERMISSION)
            ->name('auth-admin.path-b');
    });
