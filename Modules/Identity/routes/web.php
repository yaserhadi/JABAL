<?php

use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Modules\Identity\Http\Controllers\AuthController;

/*
|--------------------------------------------------------------------------
| Identity Module Web Routes (auth, dashboard) — Inertia + Vuetify
|--------------------------------------------------------------------------
*/

Route::middleware('guest')->group(function () {
    Route::get('login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('login', [AuthController::class, 'login']);
    Route::get('register', [AuthController::class, 'showRegister'])->name('register');
    Route::post('register', [AuthController::class, 'register']);
});

Route::middleware('auth')->group(function () {
    Route::post('logout', [AuthController::class, 'logout'])->name('logout');
    Route::get('dashboard', function () {
        return Inertia::render('Dashboard', [
            'tenant' => optional(\App\Support\Context\TenantContext::getInstance()->get()) ? [
                'id' => \App\Support\Context\TenantContext::getInstance()->get()->id,
                'name' => \App\Support\Context\TenantContext::getInstance()->get()->name,
            ] : null,
        ]);
    })->name('dashboard');
});
