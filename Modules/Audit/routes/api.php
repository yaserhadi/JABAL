<?php

use Illuminate\Support\Facades\Route;
use Modules\Audit\Http\Controllers\AuditController;

Route::middleware(['auth:sanctum'])->prefix('v1')->group(function () {
    Route::apiResource('audits', AuditController::class)->names('audit');
});
