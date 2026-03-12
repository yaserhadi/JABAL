<?php

use App\Http\Middleware\EnsureUserBelongsToTenant;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Modules\Identity\Http\Controllers\AuthController;
use Stancl\Tenancy\Middleware\InitializeTenancyByPath;

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

// Central routes (guest)
Route::middleware('guest')->group(function () {
    Route::get('login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('login', [AuthController::class, 'login']);
    Route::get('register', [AuthController::class, 'showRegister'])->name('register');
    Route::post('register', [AuthController::class, 'register']);
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
        InitializeTenancyByPath::class,
        EnsureUserBelongsToTenant::class,
        'permission:dashboard.view',
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
        })->name('dashboard');
    });
