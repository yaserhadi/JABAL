<?php

use App\Http\Controllers\Platform\AuthController as PlatformAuthController;
use App\Http\Middleware\EnsureNoTenancy;
use App\Http\Middleware\RedirectIfPlatformAuthenticated;
use Illuminate\Support\Facades\Route;
use Modules\Audit\Http\Controllers\AuditController;
use Modules\Settings\Http\Controllers\SettingsController;

/*
|--------------------------------------------------------------------------
| Platform Management Application (ADR-0007)
|--------------------------------------------------------------------------
*/

Route::middleware([EnsureNoTenancy::class])->prefix('platform')->name('platform.')->group(function () {
    Route::middleware(RedirectIfPlatformAuthenticated::class)->group(function () {
        Route::get('login', [PlatformAuthController::class, 'showLogin'])->name('login');
        Route::post('login', [PlatformAuthController::class, 'login'])->name('login.attempt');
    });

    Route::middleware(['auth:platform', 'platform.admin'])->group(function () {
        Route::post('logout', [PlatformAuthController::class, 'logout'])->name('logout');

        Route::get('/', fn () => redirect()->route('platform.settings.index'))->name('home');

        Route::get('settings', [SettingsController::class, 'index'])->name('settings.index');
        Route::put('settings', [SettingsController::class, 'bulkUpdate'])->name('settings.bulkUpdate');
        Route::put('settings/{key}', [SettingsController::class, 'update'])->name('settings.update');

        Route::get('audit', [AuditController::class, 'index'])->name('audit.index');
        Route::get('audit/{id}', [AuditController::class, 'show'])->name('audit.show');
    });
});
