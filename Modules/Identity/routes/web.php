<?php

use App\Http\Middleware\EnsureUserBelongsToTenant;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Modules\Identity\Http\Controllers\AuthController;
use Modules\Identity\Http\Controllers\InvitationAcceptController;
use Modules\Identity\Http\Controllers\MfaController;
use Modules\Identity\Http\Controllers\SecurityPolicyController;
use Modules\Identity\Http\Controllers\SecuritySettingsController;
use Modules\Identity\Http\Controllers\SsoAuthController;
use Modules\Identity\Http\Controllers\SsoConfigController;
use Modules\Identity\Http\Middleware\EnsureMfaVerified;
use Modules\Identity\Http\Middleware\InvitationSecurityHeaders;

/*
|--------------------------------------------------------------------------
| Identity Module Web Routes (auth, dashboard) — Inertia + Vuetify
|--------------------------------------------------------------------------
|
| PHASE 2:
| - Central routes (login, register, logout) remain unchanged
| - Dashboard moved to /t/{tenant}/dashboard with tenant context
|
*/

// Invitation accept (guest or authenticated) — token in URL only on bootstrap, then session
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

// Central routes (guest)
Route::middleware('guest')->group(function () {
    Route::get('login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('login', [AuthController::class, 'login']);
    Route::get('register', [AuthController::class, 'showRegister'])->name('register');
    Route::post('register', [AuthController::class, 'register']);

    Route::get('auth/sso/callback', [SsoAuthController::class, 'callback'])
        ->name('identity.sso.callback');
});

Route::prefix('t/{tenant}')
    ->middleware(['web', 'guest'])
    ->group(function () {
        Route::get('/login', [AuthController::class, 'showTenantLogin'])->name('tenant.login');
        Route::get('/auth/sso/redirect', [SsoAuthController::class, 'redirect'])
            ->name('identity.sso.redirect');
    });

// Central routes (auth, no tenant context)
Route::middleware('auth')->group(function () {
    Route::post('logout', [AuthController::class, 'logout'])->name('logout');
});

// Tenant-scoped routes under /t/{tenant}/...
// Enforcement order: tenancy → membership → RBAC (PHASE3B-RBAC)
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
    });

Route::prefix('t/{tenant}')
    ->middleware([
        'web',
        'auth',
        EnsureUserBelongsToTenant::class,
        EnsureMfaVerified::class,
    ])
    ->group(function () {
        Route::get('/dashboard', function () {
            $tenant = tenancy()->tenant;

            return Inertia::render('Dashboard', [
                'tenant' => $tenant ? [
                    'id' => $tenant->id,
                    'name' => $tenant->name,
                    'slug' => $tenant->slug,
                    'type' => $tenant->type,
                ] : null,
            ]);
        })->middleware('permission:dashboard.view')->name('dashboard');

        // Phase 3C: Member management (tenant admin surface)
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

        // BK-043: Tenant security policies API
        Route::get('/security/policies', [SecurityPolicyController::class, 'show'])
            ->name('identity.security-policies.show');
        Route::patch('/security/policies', [SecurityPolicyController::class, 'update'])
            ->name('identity.security-policies.update');

        // BK-008: Tenant SSO configuration admin API
        Route::get('/security/sso', [SsoConfigController::class, 'show'])
            ->name('identity.sso.show');
        Route::patch('/security/sso', [SsoConfigController::class, 'update'])
            ->name('identity.sso.update');

        // BK-035: Tenant security settings UI hub
        Route::get('/security/settings', [SecuritySettingsController::class, 'show'])
            ->name('identity.security-settings.show');
        Route::patch('/security/settings/policies', [SecuritySettingsController::class, 'updatePolicies'])
            ->middleware('permission:tenant.security-policy.update')
            ->name('identity.security-settings.update-policies');
        Route::delete('/security/settings/sessions/{session}', [SecuritySettingsController::class, 'revokeSession'])
            ->name('identity.security-settings.revoke-session');
        Route::delete('/security/settings/sessions', [SecuritySettingsController::class, 'revokeOtherSessions'])
            ->name('identity.security-settings.revoke-other-sessions');
    });
