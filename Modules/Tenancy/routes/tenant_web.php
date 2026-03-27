<?php

use App\Http\Middleware\EnsureUserBelongsToTenant;
use Illuminate\Support\Facades\Route;
use Modules\Tenancy\Http\Controllers\TenantSettingsController;
use Stancl\Tenancy\Middleware\InitializeTenancyByPath;

/*
|--------------------------------------------------------------------------
| Tenancy — tenant path routes (Phase 3D settings)
|--------------------------------------------------------------------------
|
| /t/{tenant}/... — enforcement order: tenancy → membership → RBAC (controller).
|
*/

Route::prefix('t/{tenant}')
    ->middleware([
        'web',
        'auth',
        InitializeTenancyByPath::class,
        EnsureUserBelongsToTenant::class,
    ])
    ->group(function () {
        Route::get('settings', [TenantSettingsController::class, 'show'])->name('tenant.settings.index');
        Route::patch('settings', [TenantSettingsController::class, 'update'])->name('tenant.settings.update');
    });
