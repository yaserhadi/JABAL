<?php

use App\Http\Middleware\EnsureUserBelongsToTenant;
use App\Support\Tenancy\TenantAddressingProfile;
use App\Support\Tenancy\TenantRouteRegistrar;
use Illuminate\Support\Facades\Route;
use Modules\Audit\Http\Controllers\TenantAuditController;
use Modules\Identity\Http\Middleware\EnsureMfaVerified;

/*
|--------------------------------------------------------------------------
| Audit — tenant routes (BK-020 / BK-073)
|--------------------------------------------------------------------------
*/

$addressing = app(TenantAddressingProfile::class);

if ($addressing->isHost()) {
    app(TenantRouteRegistrar::class)->onTenantHost(function () {
        Route::middleware([
            'web',
            'auth',
            EnsureUserBelongsToTenant::class,
            EnsureMfaVerified::class,
        ])->group(function () {
            Route::get('audit', [TenantAuditController::class, 'index'])->name('tenant.audit.index');
        });
    });

    return;
}

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
