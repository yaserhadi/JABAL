<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;
use Modules\Identity\Events\UserRegistered;
use Modules\Tenancy\Events\TenantCreated;
use Modules\Tenancy\Models\Tenant;
use Modules\Tenancy\Models\TenantUser;

class RegisterController extends Controller
{
    /**
     * Show the registration form.
     */
    public function showRegistrationForm(): View
    {
        return view('auth.register');
    }

    /**
     * Handle a registration request.
     * Creates user, personal tenant, and owner membership (Phase 1).
     */
    public function register(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:users'],
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
        ]);

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
        ]);

        $tenant = Tenant::create([
            'name' => $user->name.'\'s Workspace',
            'slug' => Str::slug($user->name).'-'.Str::random(6),
            'type' => 'personal',
            'isolation_level' => 'shared',
        ]);

        TenantUser::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'membership_type' => 'owner',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        event(new Registered($user));
        UserRegistered::dispatch($user);
        TenantCreated::dispatch($tenant);

        Auth::login($user);

        $request->session()->regenerate();

        return redirect(route('dashboard', absolute: false));
    }
}
