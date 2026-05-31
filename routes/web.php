<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Root Web Routes (Lock 1: minimal only - bootstrapping/redirect)
|--------------------------------------------------------------------------
| All functional routes (auth, dashboard, admin) live in module route files.
|
| PHASE 2: Root redirect goes to /t/{personalTenantId}/dashboard
*/

Route::get('/', function () {
    if (auth('web')->check()) {
        $user = auth('web')->user();
        $personalTenant = $user->personalTenant();
        if ($personalTenant) {
            return redirect('/t/'.$personalTenant->id.'/dashboard');
        }

        return redirect()->route('login');
    }

    return redirect()->route('login');
});
