<?php

use Illuminate\Support\Facades\Route;
use Modules\Api\Http\Controllers\ApiController;

Route::middleware(['auth:sanctum'])->prefix('v1')->group(function () {
    Route::apiResource('apis', ApiController::class)->names('api');
});
