<?php

use App\Support\Context\TenantContext;
use Illuminate\Support\Facades\Route;
use Modules\Api\Http\ApiResponse;
use Modules\Identity\Http\Controllers\Api\TokenController;

/*
|--------------------------------------------------------------------------
| API v1 Routes
|--------------------------------------------------------------------------
|
| All API routes are prefixed with /api/v1
| Authentication via Laravel Sanctum
|
*/

Route::prefix('v1')->name('api.v1.')->group(function () {
    // Public routes
    Route::get('/health', function () {
        return ApiResponse::success([
            'status' => 'ok',
            'timestamp' => now()->toIso8601String(),
            'version' => 'v1',
        ]);
    })->name('health');

    // Authentication routes (public)
    Route::post('/auth/token', [TokenController::class, 'store'])->name('auth.token.store');

    // Protected routes
    Route::middleware('auth:sanctum')->group(function () {
        // Current user endpoint with tenant context (GET /api/v1/me)
        Route::get('/me', function () {
            $user = auth()->user();
            $tenant = TenantContext::getInstance()->get();
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
        })->name('me');

        // Token management
        Route::delete('/auth/token', [TokenController::class, 'destroy'])->name('auth.token.destroy');

        // Tenant routes (placeholder)
        Route::prefix('tenants')->name('tenants.')->group(function () {
            // TODO: Add tenant routes
        });
    });
});
