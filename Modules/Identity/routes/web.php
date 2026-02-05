<?php

use Illuminate\Support\Facades\Route;
use Modules\Identity\Http\Controllers\IdentityController;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::resource('identities', IdentityController::class)->names('identity');
});
