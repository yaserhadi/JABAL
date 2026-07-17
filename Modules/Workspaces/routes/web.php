<?php

use App\Http\Middleware\EnsureUserBelongsToTenant;
use App\Support\Tenancy\TenantAddressingProfile;
use App\Support\Tenancy\TenantRouteRegistrar;
use Illuminate\Support\Facades\Route;
use Modules\Workspaces\Http\Controllers\WorkspacesController;

/*
|--------------------------------------------------------------------------
| Workspaces Module Web Routes (Phase 3C / BK-073)
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
            Route::resource('workspaces', WorkspacesController::class)->names('workspaces');
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
        Route::resource('workspaces', WorkspacesController::class)->names('workspaces');
    });
