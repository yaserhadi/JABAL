<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Root Web Routes (Lock 1: minimal only - bootstrapping/redirect)
|--------------------------------------------------------------------------
| All functional routes (auth, dashboard, admin) live in module route files.
|
| BK-064: Root redirect uses Membership-based homeTenant (slug path preferred).
*/

Route::get('/', function () {
    if (auth('web')->check()) {
        $user = auth('web')->user();
        $homeTenant = $user->homeTenant();
        if ($homeTenant) {
            return redirect('/t/'.$homeTenant->entryKey().'/dashboard');
        }

        return redirect()->route('login');
    }

    return redirect()->route('login');
});
