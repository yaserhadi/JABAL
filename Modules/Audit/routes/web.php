<?php

use Illuminate\Support\Facades\Route;
use Modules\Audit\Http\Controllers\AuditController;

Route::middleware(['auth'])->prefix('admin')->name('audit.')->group(function () {
    Route::get('audit', [AuditController::class, 'index'])->name('index');
    Route::get('audit/{id}', [AuditController::class, 'show'])->name('show');
});
