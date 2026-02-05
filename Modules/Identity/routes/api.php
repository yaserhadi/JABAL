<?php

use Illuminate\Support\Facades\Route;
use Modules\Identity\Http\Controllers\IdentityController;

Route::middleware(['auth:sanctum'])->prefix('v1')->group(function () {
    Route::apiResource('identities', IdentityController::class)->names('identity');
});
