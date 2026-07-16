<?php

use App\Http\Controllers\Platform\AuthController as PlatformAuthController;
use App\Http\Middleware\EnsureNoTenancy;
use App\Http\Middleware\RedirectIfPlatformAuthenticated;
use Illuminate\Support\Facades\Route;
use Modules\Audit\Http\Controllers\AuditController;
use Modules\Billing\Http\Controllers\Platform\PlatformPlanController;
use Modules\Billing\Http\Controllers\Platform\PlatformSubscriptionController;
use Modules\Settings\Http\Controllers\SettingsController;
use Modules\Tenancy\Http\Controllers\PlatformTenantOnboardingController;
use Modules\Tenancy\Http\Controllers\PlatformTenantRegistryController;

/*
|--------------------------------------------------------------------------
| Platform Management Application (ADR-0007)
|--------------------------------------------------------------------------
*/

Route::middleware([EnsureNoTenancy::class])->prefix('platform')->name('platform.')->group(function () {
    Route::middleware(RedirectIfPlatformAuthenticated::class)->group(function () {
        Route::get('login', [PlatformAuthController::class, 'showLogin'])->name('login');
        Route::post('login', [PlatformAuthController::class, 'login'])->name('login.attempt');
    });

    Route::middleware(['auth:platform', 'platform.admin'])->group(function () {
        Route::post('logout', [PlatformAuthController::class, 'logout'])->name('logout');

        Route::get('/', fn () => redirect()->route('platform.settings.index'))->name('home');

        Route::get('settings', [SettingsController::class, 'index'])->name('settings.index');
        Route::put('settings', [SettingsController::class, 'bulkUpdate'])->name('settings.bulkUpdate');
        Route::put('settings/{key}', [SettingsController::class, 'update'])->name('settings.update');

        Route::get('audit', [AuditController::class, 'index'])->name('audit.index');
        Route::get('audit/{id}', [AuditController::class, 'show'])->name('audit.show');

        // BK-069 Platform Tenant Registry — fine-grained permissions (billing pattern)
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

        // Legacy JSON onboard alias (same permission) — routes to registry create for BK-005 reuse
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
    });
});
