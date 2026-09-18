<?php

use Illuminate\Support\Facades\Route;
use Modules\Identity\Http\Controllers\AuthController;

/*
|--------------------------------------------------------------------------
| Root Web Routes (Lock 1: minimal only - bootstrapping/redirect)
|--------------------------------------------------------------------------
|
| All functional routes (auth, dashboard, admin) live in module route files.
|
| BK-114 / UAT-OBS-001: Apex guest `/` renders public Landing (not /login).
| Authenticated web users and non-Apex Host roots are handled in showLanding.
*/

Route::get('/', [AuthController::class, 'showLanding'])->name('home');
