<?php

namespace Modules\Identity\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Identity\Events\UserRegistered;
use Modules\Identity\Http\Requests\RegisterRequest;
use Modules\Identity\Services\UserService;
use Modules\Tenancy\Events\TenantCreated;

class RegisterController extends Controller
{
    /**
     * @var UserService
     */
    protected UserService $userService;

    public function __construct(UserService $userService)
    {
        $this->userService = $userService;
    }

    /**
     * Show the registration form.
     *
     * @return \Inertia\Response
     */
    public function showRegisterForm(): Response
    {
        return Inertia::render('Auth/Register');
    }

    /**
     * Handle a registration request.
     *
     * Creates user, personal tenant, and owner membership within a transaction.
     *
     * @param \Modules\Identity\Http\Requests\RegisterRequest $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function register(RegisterRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        $user = DB::transaction(function () use ($validated) {
            // Create user
            $user = User::create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password' => Hash::make($validated['password']),
            ]);

            // Create personal tenant using UserService
            $tenant = $this->userService->createPersonalTenant($user);

            // Dispatch events
            UserRegistered::dispatch($user);
            TenantCreated::dispatch($tenant);

            return $user;
        });

        // Login the user
        Auth::login($user);

        $request->session()->regenerate();

        return redirect(route('dashboard', absolute: false));
    }
}
