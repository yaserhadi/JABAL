<?php

namespace Modules\Identity\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Modules\Identity\Events\UserRegistered;
use Modules\Identity\Services\UserService;
use Modules\Tenancy\Events\TenantCreated;

class AuthController extends Controller
{
    /**
     * Show the login form.
     */
    public function showLogin()
    {
        return Inertia::render('Auth/Login');
    }

    /**
     * Handle login request.
     *
     * PHASE 2: Redirects to tenant-scoped dashboard /t/{tenant}/dashboard
     */
    public function login(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);

        if (! Auth::attempt($request->only('email', 'password'), $request->boolean('remember'))) {
            throw ValidationException::withMessages([
                'email' => __('The provided credentials do not match our records.'),
            ]);
        }

        $request->session()->regenerate();

        $user = Auth::user();
        $personalTenant = $user->personalTenant();

        if ($personalTenant) {
            return redirect()->intended('/t/'.$personalTenant->id.'/dashboard');
        }

        return redirect()->route('login');
    }

    /**
     * Show the registration form.
     */
    public function showRegister()
    {
        return Inertia::render('Auth/Register');
    }

    /**
     * Handle registration request.
     * Creates user, personal tenant, and owner membership (Phase 1).
     *
     * PHASE 2: Redirects to tenant-scoped dashboard /t/{tenant}/dashboard
     */
    public function register(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:8|confirmed',
        ]);

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
        ]);

        $userService = app(UserService::class);
        $tenant = $userService->createPersonalTenant($user);

        event(new UserRegistered($user));
        event(new TenantCreated($tenant));

        Auth::login($user);

        $request->session()->regenerate();

        return redirect('/t/'.$tenant->id.'/dashboard');
    }

    /**
     * Logout the authenticated user.
     */
    public function logout(Request $request)
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }

    /**
     * Show the password reset request form.
     */
    public function showForgotPassword()
    {
        return Inertia::render('Auth/ForgotPassword');
    }

    /**
     * Show the password reset form.
     */
    public function showResetPassword(string $token)
    {
        return Inertia::render('Auth/ResetPassword', [
            'token' => $token,
        ]);
    }
}
