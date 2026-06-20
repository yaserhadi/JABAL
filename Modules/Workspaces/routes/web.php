<?php

use App\Http\Middleware\EnsureUserBelongsToTenant;
use Illuminate\Support\Facades\Route;
use Modules\Workspaces\Http\Controllers\WorkspacesController;

/*
|--------------------------------------------------------------------------
| Workspaces Module Web Routes (Phase 3C)
|--------------------------------------------------------------------------
|
| Tenant-scoped under /t/{tenant}/workspaces.
| Enforcement order: tenancy → membership → RBAC.
| Per-action permissions applied in controller.
|
*/

Route::prefix('t/{tenant}')
    ->middleware([
        'web',
        'auth',
        EnsureUserBelongsToTenant::class,
    ])
    ->group(function () {
        Route::resource('workspaces', WorkspacesController::class)->names('workspaces');
    });
