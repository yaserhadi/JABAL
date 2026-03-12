<?php

use Illuminate\Support\Facades\Route;
use Modules\Workspaces\Http\Controllers\WorkspacesController;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::resource('workspaces', WorkspacesController::class)->names('workspaces');
});
