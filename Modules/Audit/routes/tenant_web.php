<?php

use App\Http\Middleware\EnsureUserBelongsToTenant;
use Illuminate\Support\Facades\Route;
use Modules\Audit\Http\Controllers\TenantAuditController;
use Modules\Identity\Http\Middleware\EnsureMfaVerified;

/*
|--------------------------------------------------------------------------
| Audit — tenant path routes (BK-020)
|--------------------------------------------------------------------------
|
| /t/{tenant}/audit — enforcement order: tenancy → membership → MFA → RBAC.
|
*/

Route::prefix('t/{tenant}')
    ->middleware([
        'web',
        'auth',
        EnsureUserBelongsToTenant::class,
        EnsureMfaVerified::class,
    ])
    ->group(function () {
        Route::get('audit', [TenantAuditController::class, 'index'])->name('tenant.audit.index');
    });
