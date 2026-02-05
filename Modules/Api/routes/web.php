<?php

use Illuminate\Support\Facades\Route;
use Modules\Api\Http\Controllers\ApiController;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::resource('apis', ApiController::class)->names('api');
});
