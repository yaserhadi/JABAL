<?php

use App\Http\Auth\TenantEntryUrlResolver;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Root Web Routes (Lock 1: minimal only - bootstrapping/redirect)
|--------------------------------------------------------------------------
|
| All functional routes (auth, dashboard, admin) live in module route files.
|
| BK-064/BK-073: Root redirect uses Membership-based homeTenant.
*/

Route::get('/', function () {
    if (auth('web')->check()) {
        $user = auth('web')->user();
        $homeTenant = $user->homeTenant();
        if ($homeTenant) {
            return redirect()->to(app(TenantEntryUrlResolver::class)->dashboardUrl($homeTenant));
        }

        return redirect()->route('login');
    }

    return redirect()->route('login');
});
