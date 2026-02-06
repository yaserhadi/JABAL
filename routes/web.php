<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Root Web Routes (Lock 1: minimal only - bootstrapping/redirect)
|--------------------------------------------------------------------------
| All functional routes (auth, dashboard, admin) live in module route files.
*/

Route::get('/', function () {
    if (auth()->check()) {
        return redirect()->route('dashboard');
    }
    return redirect()->route('login');
});
