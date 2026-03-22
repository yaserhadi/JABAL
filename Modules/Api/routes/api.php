<?php

use App\Http\Middleware\ValidateTenantToken;
use Illuminate\Support\Facades\Route;
use Modules\Api\Http\ApiResponse;
use Modules\Identity\Http\Controllers\Api\TokenController;
use Modules\Tenancy\Http\Controllers\TenantMemberController;
use Modules\Workspaces\Http\Controllers\WorkspacesController;
use Stancl\Tenancy\Middleware\InitializeTenancyByRequestData;

/*
|--------------------------------------------------------------------------
| API v1 Routes
|--------------------------------------------------------------------------
|
| All API routes are prefixed with /api/v1
| Authentication via Laravel Sanctum
|
| PHASE 2: Tenant-scoped routes use InitializeTenancyByRequestData + ValidateTenantToken
| Tenant is identified via X-Tenant-Id header, validated against token ability tenant:{uuid}
|
*/

Route::prefix('v1')->name('api.v1.')->group(function () {
    // Public routes (no tenant context)
    Route::get('/health', function () {
        return ApiResponse::success([
            'status' => 'ok',
            'timestamp' => now()->toIso8601String(),
            'version' => 'v1',
        ]);
    })->name('health');

    // Authentication routes (public, no tenant context)
    Route::post('/auth/token', [TokenController::class, 'store'])->name('auth.token.store');

    // Protected routes (require auth but NOT tenant context)
    Route::middleware('auth:sanctum')->group(function () {
        // Token management (central, no tenant context needed)
        Route::delete('/auth/token', [TokenController::class, 'destroy'])->name('auth.token.destroy');
    });

    // Tenant-scoped protected routes (require auth + tenant context + RBAC)
    // Enforcement order: tenancy → membership (ValidateTenantToken) → RBAC (PHASE3B-RBAC)
    Route::middleware([
        'auth:sanctum',
        InitializeTenancyByRequestData::class,
        ValidateTenantToken::class,
    ])->group(function () {
        // Current user endpoint (requires dashboard.view)
        Route::apiResource('workspaces', WorkspacesController::class)->names('workspaces');

        Route::prefix('tenants/current')->name('tenants.current.')->group(function () {
            Route::get('members', [TenantMemberController::class, 'index'])
                ->middleware('permission:member.view')
                ->name('members.index');
            Route::patch('members/{user}', [TenantMemberController::class, 'updateRole'])
                ->middleware('permission:member.assign-role')
                ->name('members.update');
            Route::post('members/{user}/suspend', [TenantMemberController::class, 'suspend'])
                ->middleware('permission:member.suspend')
                ->name('members.suspend');
            Route::post('members/{user}/activate', [TenantMemberController::class, 'activate'])
                ->middleware('permission:member.suspend')
                ->name('members.activate');
        });

        Route::get('/me', function () {
            $user = auth()->user();
            $tenant = tenancy()->tenant;
            $currentTenant = $tenant ? [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'slug' => $tenant->slug,
                'type' => $tenant->type,
            ] : null;

            return ApiResponse::success([
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'current_tenant' => $currentTenant,
            ]);
        })->middleware('permission:dashboard.view')->name('me');

        // Tenant routes (placeholder)
        Route::prefix('tenants')->name('tenants.')->group(function () {
            // TODO: Add tenant routes
        });
    });
});
