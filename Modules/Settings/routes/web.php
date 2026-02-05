<?php

use Illuminate\Support\Facades\Route;
use Modules\Settings\Http\Controllers\SettingsController;

Route::middleware(['auth'])->prefix('admin')->name('settings.')->group(function () {
    Route::get('settings', [SettingsController::class, 'index'])->name('index');
    Route::put('settings/{key}', [SettingsController::class, 'update'])->name('update');
});
