<?php

use App\Http\Controllers\Platform\AuthController as PlatformAuthController;
use App\Http\Controllers\Platform\DashboardController as PlatformDashboardController;
use App\Http\Middleware\EnsureNoTenancy;
use App\Http\Middleware\RedirectIfPlatformAuthenticated;
use App\Support\Tenancy\TenantAddressingProfile;
use Illuminate\Support\Facades\Route;
use Modules\Audit\Http\Controllers\AuditController;
use Modules\Billing\Http\Controllers\Platform\PlatformCatalogController;
use Modules\Billing\Http\Controllers\Platform\PlatformPlanController;
use Modules\Billing\Http\Controllers\Platform\PlatformSubscriptionController;
use Modules\Settings\Http\Controllers\SettingsController;
use Modules\Tenancy\Http\Controllers\PlatformLegalOrganizationController;
use Modules\Tenancy\Http\Controllers\PlatformTenantOnboardingController;
use Modules\Tenancy\Http\Controllers\PlatformTenantRegistryController;

/*
|--------------------------------------------------------------------------
| Platform Management Application (ADR-0007 / BK-125 Wave 5 CONFLICT-D)
|--------------------------------------------------------------------------
|
| PATH:     {apex}/platform/*  (prefix identifies Platform plane)
| PATH_HOST:{platform-host}/*  (host identifies Platform plane; no /platform URI prefix)
|
| Named routes remain platform.* in both profiles.
| Authenticated home = platform.dashboard (/dashboard or /platform/dashboard).
*/

$addressing = app(TenantAddressingProfile::class);

$registerAuthenticatedPlatformRoutes = function (bool $includePrefixedHome): void {
    Route::post('logout', [PlatformAuthController::class, 'logout'])->name('logout');

    Route::get('dashboard', [PlatformDashboardController::class, 'show'])->name('dashboard');

    if ($includePrefixedHome) {
        Route::get('/', function () {
            return redirect()->route('platform.dashboard');
        })->name('home');
    }

    Route::get('settings', [SettingsController::class, 'index'])->name('settings.index');
    Route::put('settings', [SettingsController::class, 'bulkUpdate'])->name('settings.bulkUpdate');
    Route::put('settings/{key}', [SettingsController::class, 'update'])->name('settings.update');

    Route::get('audit', [AuditController::class, 'index'])->name('audit.index');
    Route::get('audit/{id}', [AuditController::class, 'show'])->name('audit.show');

    Route::get('tenants', [PlatformTenantRegistryController::class, 'index'])
        ->middleware('platform.permission:platform.tenants.view')
        ->name('tenants.index');

    Route::get('tenants/create', [PlatformTenantRegistryController::class, 'create'])
        ->middleware('platform.permission:platform.tenants.create')
        ->name('tenants.create');

    Route::post('tenants/handle-availability', [PlatformTenantRegistryController::class, 'checkHandleAvailability'])
        ->middleware(['platform.permission:platform.tenants.create', 'throttle:tenant-handle-availability'])
        ->name('tenants.handle-availability');

    Route::post('tenants', [PlatformTenantRegistryController::class, 'store'])
        ->middleware('platform.permission:platform.tenants.create')
        ->name('tenants.store');

    Route::post('tenants/onboard', [PlatformTenantOnboardingController::class, 'store'])
        ->middleware('platform.permission:platform.tenants.create')
        ->name('tenants.onboard');

    Route::get('tenants/{tenant}', [PlatformTenantRegistryController::class, 'show'])
        ->middleware('platform.permission:platform.tenants.view')
        ->name('tenants.show');

    Route::get('tenants/{tenant}/edit', [PlatformTenantRegistryController::class, 'edit'])
        ->middleware('platform.permission:platform.tenants.update')
        ->name('tenants.edit');

    Route::patch('tenants/{tenant}', [PlatformTenantRegistryController::class, 'update'])
        ->middleware('platform.permission:platform.tenants.update')
        ->name('tenants.update');

    Route::post('tenants/{tenant}/handle', [PlatformTenantRegistryController::class, 'renameHandle'])
        ->middleware('platform.permission:platform.tenants.update')
        ->name('tenants.rename-handle');

    Route::get('emergency', [\App\Http\Controllers\Platform\PlatformEmergencyAuthorityController::class, 'index'])
        ->middleware('platform.permission:platform.emergency.operate')
        ->name('emergency.index');
    Route::post('emergency/invoke', [\App\Http\Controllers\Platform\PlatformEmergencyAuthorityController::class, 'invoke'])
        ->middleware('platform.permission:platform.emergency.operate')
        ->name('emergency.invoke');
    Route::post('emergency/{caseId}/close', [\App\Http\Controllers\Platform\PlatformEmergencyAuthorityController::class, 'close'])
        ->middleware('platform.permission:platform.emergency.operate')
        ->name('emergency.close');

    Route::get('catalog', [PlatformCatalogController::class, 'index'])
        ->middleware('platform.permission:platform.billing.view')
        ->name('catalog.index');

    Route::get('legal-organizations', [PlatformLegalOrganizationController::class, 'index'])
        ->middleware('platform.permission:platform.tenants.view')
        ->name('legal-organizations.index');
    Route::post('legal-organizations', [PlatformLegalOrganizationController::class, 'store'])
        ->middleware('platform.permission:platform.tenants.create')
        ->name('legal-organizations.store');
    Route::get('legal-organizations/{legalOrganization}', [PlatformLegalOrganizationController::class, 'show'])
        ->middleware('platform.permission:platform.tenants.view')
        ->name('legal-organizations.show');
    Route::post('legal-organizations/{legalOrganization}/business-owners', [PlatformLegalOrganizationController::class, 'assignOwner'])
        ->middleware('platform.permission:platform.tenants.update')
        ->name('legal-organizations.business-owners.assign');

    Route::prefix('billing')->name('billing.')->group(function () {
        Route::get('plans', [PlatformPlanController::class, 'index'])
            ->middleware('platform.permission:platform.billing.view')
            ->name('plans.index');

        Route::get('tenants/{tenant}/subscription', [PlatformSubscriptionController::class, 'show'])
            ->middleware('platform.permission:platform.billing.view')
            ->name('tenants.subscription.show');

        Route::patch('tenants/{tenant}/subscription/plan', [PlatformSubscriptionController::class, 'changePlan'])
            ->middleware('platform.permission:platform.billing.manage')
            ->name('tenants.subscription.change-plan');

        Route::patch('tenants/{tenant}/subscription/seat-limit', [PlatformSubscriptionController::class, 'updateSeatLimit'])
            ->middleware('platform.permission:platform.billing.manage')
            ->name('tenants.subscription.seat-limit');

        Route::post('tenants/{tenant}/subscription/suspend', [PlatformSubscriptionController::class, 'suspend'])
            ->middleware('platform.permission:platform.billing.manage')
            ->name('tenants.subscription.suspend');

        Route::post('tenants/{tenant}/subscription/reactivate', [PlatformSubscriptionController::class, 'reactivate'])
            ->middleware('platform.permission:platform.billing.manage')
            ->name('tenants.subscription.reactivate');

        Route::post('tenants/{tenant}/subscription/cancel', [PlatformSubscriptionController::class, 'cancel'])
            ->middleware('platform.permission:platform.billing.manage')
            ->name('tenants.subscription.cancel');
    });
};

Route::middleware([EnsureNoTenancy::class])->group(function () use ($addressing, $registerAuthenticatedPlatformRoutes) {
    if ($addressing->isPathHost()) {
        Route::middleware(RedirectIfPlatformAuthenticated::class)->group(function () {
            Route::get('login', [PlatformAuthController::class, 'showLogin'])->name('platform.login');
            Route::post('login', [PlatformAuthController::class, 'login'])->name('platform.login.attempt');
        });

        Route::middleware(['auth:platform', 'platform.admin'])
            ->name('platform.')
            ->group(fn () => $registerAuthenticatedPlatformRoutes(false));

        // Platform Host root: guest → login; authenticated → dashboard (not a second app page).
        Route::get('/', function () {
            if (auth('platform')->check()) {
                return redirect()->route('platform.dashboard');
            }

            return redirect()->route('platform.login');
        })->name('platform.home');
    } else {
        Route::prefix('platform')->name('platform.')->group(function () use ($registerAuthenticatedPlatformRoutes) {
            Route::middleware(RedirectIfPlatformAuthenticated::class)->group(function () {
                Route::get('login', [PlatformAuthController::class, 'showLogin'])->name('login');
                Route::post('login', [PlatformAuthController::class, 'login'])->name('login.attempt');
            });

            Route::middleware(['auth:platform', 'platform.admin'])->group(fn () => $registerAuthenticatedPlatformRoutes(true));
        });
    }
});
