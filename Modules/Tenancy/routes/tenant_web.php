<?php

use App\Http\Middleware\EnsureUserBelongsToTenant;
use App\Support\Tenancy\TenantAddressingProfile;
use App\Support\Tenancy\TenantRouteRegistrar;
use Illuminate\Support\Facades\Route;
use Modules\Tenancy\Http\Controllers\TenantSettingsController;
use Modules\Tenancy\Http\Controllers\TenantSetupController;

/*
|--------------------------------------------------------------------------
| Tenancy — tenant routes (Phase 3D settings)
|--------------------------------------------------------------------------
*/

$addressing = app(TenantAddressingProfile::class);

if ($addressing->isHost()) {
    app(TenantRouteRegistrar::class)->onTenantHost(function () {
        Route::middleware([
            'web',
            'auth',
            EnsureUserBelongsToTenant::class,
        ])->group(function () {
            Route::get('settings', [TenantSettingsController::class, 'show'])->name('tenant.settings.index');
            Route::patch('settings', [TenantSettingsController::class, 'update'])->name('tenant.settings.update');
            Route::get('setup', [TenantSetupController::class, 'show'])->name('tenant.setup.index');
            Route::post('setup/complete', [TenantSetupController::class, 'complete'])->name('tenant.setup.complete');
        });
    });

    return;
}

Route::prefix('t/{tenant}')
    ->middleware([
        'web',
        'auth',
        EnsureUserBelongsToTenant::class,
    ])
    ->group(function () {
        Route::get('settings', [TenantSettingsController::class, 'show'])->name('tenant.settings.index');
        Route::patch('settings', [TenantSettingsController::class, 'update'])->name('tenant.settings.update');
        Route::get('setup', [TenantSetupController::class, 'show'])->name('tenant.setup.index');
        Route::post('setup/complete', [TenantSetupController::class, 'complete'])->name('tenant.setup.complete');
    });
