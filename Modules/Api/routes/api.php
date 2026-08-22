<?php

use App\Http\Middleware\ValidateTenantToken;
use Illuminate\Support\Facades\Route;
use Modules\Api\Http\ApiResponse;
use Modules\Identity\Http\Controllers\Api\TokenController;
use Modules\Tenancy\Http\Controllers\TenantMemberController;
use Modules\Tenancy\Http\Controllers\TenantSettingsController;
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
    Route::post('/auth/token', [TokenController::class, 'store'])
        ->middleware('throttle:api-token-grant')
        ->name('auth.token.store');

    Route::middleware([
        ValidateTenantToken::class,
        'auth:sanctum',
        InitializeTenancyByRequestData::class,
    ])->group(function () {
        Route::get('/auth/tokens', [TokenController::class, 'index'])->name('auth.tokens.index');
        Route::delete('/auth/token', [TokenController::class, 'destroy'])->name('auth.token.destroy');
        Route::delete('/auth/tokens/{tokenId}', [TokenController::class, 'destroyById'])->name('auth.tokens.destroy');
    });

    // Tenant-scoped protected routes (require auth + tenant context + RBAC)
    // Order: token/header/membership (403 on mismatch before auth) → sanctum → tenancy init
    Route::middleware([
        ValidateTenantToken::class,
        'auth:sanctum',
        InitializeTenancyByRequestData::class,
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
            Route::post('members/invite', [TenantMemberController::class, 'invite'])
                ->middleware('permission:member.invite')
                ->name('members.invite');
            Route::post('members/invite-existing', [TenantMemberController::class, 'inviteExisting'])
                ->middleware('permission:member.invite')
                ->name('members.invite-existing');
            Route::post('members/invitations/{invitation}/resend', [TenantMemberController::class, 'resendInvitation'])
                ->middleware('permission:member.invite')
                ->name('members.resend-invitation');
            Route::delete('members/invitations/{invitation}', [TenantMemberController::class, 'revokeInvitation'])
                ->middleware('permission:member.invite')
                ->name('members.revoke-invitation');
            Route::delete('members/{user}', [TenantMemberController::class, 'remove'])
                ->middleware('permission:member.remove')
                ->name('members.remove');
            Route::post('members/{user}/restore', [TenantMemberController::class, 'restore'])
                ->middleware('permission:member.remove')
                ->name('members.restore');
            Route::delete('members/{user}/permanent', [TenantMemberController::class, 'deleteForever'])
                ->middleware('permission:member.remove')
                ->name('members.delete-forever');
            Route::post('members/{user}/transfer-ownership', [TenantMemberController::class, 'transferOwnership'])
                ->name('members.transfer');

            Route::get('settings', [TenantSettingsController::class, 'show'])
                ->middleware('permission:tenant.settings.view')
                ->name('settings.show');
            Route::patch('settings', [TenantSettingsController::class, 'update'])
                ->middleware('permission:tenant.settings.update')
                ->name('settings.update');
        });

        Route::get('/me', function () {
            $user = auth()->user();
            $tenant = tenancy()->tenant;
            $currentTenant = $tenant ? [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'slug' => $tenant->slug,
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
