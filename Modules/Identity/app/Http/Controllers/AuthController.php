<?php

namespace Modules\Identity\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Modules\Identity\Events\UserRegistered;
use Modules\Identity\Models\TenantUser;
use Modules\Identity\Services\TenantRegistrationService;
use Modules\Tenancy\Events\TenantCreated;
use Modules\Tenancy\Models\Tenant;

class AuthController extends Controller
{
    public function showLogin()
    {
        return Inertia::render('Auth/Login');
    }

    public function login(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);

        $tenantUser = TenantUser::findForLogin($request->input('email'));

        if (! $tenantUser) {
            throw ValidationException::withMessages([
                'email' => __('The provided credentials do not match our records.'),
            ]);
        }

        $tenant = Tenant::find($tenantUser->tenant_id);
        if (! $tenant || $tenant->status !== 'active') {
            throw ValidationException::withMessages([
                'email' => __('The provided credentials do not match our records.'),
            ]);
        }

        tenancy()->initialize($tenant);

        if (! Auth::guard('web')->attempt(
            $request->only('email', 'password'),
            $request->boolean('remember')
        )) {
            tenancy()->end();
            throw ValidationException::withMessages([
                'email' => __('The provided credentials do not match our records.'),
            ]);
        }

        $request->session()->regenerate();
        $request->session()->put('tenant_id', $tenant->id);

        return redirect()->intended('/t/'.$tenant->id.'/dashboard');
    }

    public function showRegister()
    {
        return Inertia::render('Auth/Register');
    }

    public function register(Request $request, TenantRegistrationService $registration)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255',
            'password' => 'required|string|min:8|confirmed',
        ]);

        $tenantUser = $registration->registerTenantUser(
            $validated['name'],
            $validated['email'],
            $validated['password']
        );

        $tenant = $tenantUser->personalTenant();

        event(new UserRegistered($tenantUser));
        if ($tenant) {
            event(new TenantCreated($tenant));
        }

        tenancy()->initialize($tenant);
        Auth::guard('web')->login($tenantUser);
        $request->session()->regenerate();
        $request->session()->put('tenant_id', $tenant->id);

        $target = '/t/'.$tenant->id.'/dashboard';

        if ($request->header('X-Inertia')) {
            return Inertia::location($target);
        }

        return redirect($target);
    }

    public function logout(Request $request)
    {
        Auth::guard('web')->logout();
        if (tenancy()->initialized) {
            tenancy()->end();
        }
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
