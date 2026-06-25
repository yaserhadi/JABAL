<?php

namespace Modules\Identity\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Modules\Identity\Services\TenantInvitationService;
use Modules\Tenancy\Models\Tenant;

class InvitationAcceptController extends Controller
{
    public function __construct(
        private TenantInvitationService $invitationService
    ) {}

    public function show(string $token): InertiaResponse|RedirectResponse
    {
        $invitation = $this->invitationService->findValidByToken($token);
        if (! $invitation) {
            abort(404, 'This invitation is invalid or has expired.');
        }

        $tenant = Tenant::query()->find($invitation->tenant_id);
        $user = auth()->user();
        $emailMatches = $user && strtolower($user->email) === strtolower($invitation->email);

        return Inertia::render('Invitations/Accept', [
            'token' => $token,
            'email' => $invitation->email,
            'tenant' => $tenant ? [
                'id' => $tenant->id,
                'name' => $tenant->name,
            ] : null,
            'isAuthenticated' => (bool) $user,
            'emailMatches' => $emailMatches,
        ]);
    }

    public function accept(Request $request, string $token): RedirectResponse
    {
        $user = auth()->user();
        if (! $user) {
            return redirect()->route('login')->with('url.intended', url('/invitations/'.$token));
        }

        try {
            $membership = $this->invitationService->acceptInvitation($token, $user);
        } catch (ValidationException $e) {
            throw $e;
        }

        $tenant = Tenant::query()->findOrFail($membership->tenant_id);

        return redirect('/t/'.$tenant->id.'/dashboard')
            ->with('success', 'You have joined the workspace.');
    }

    public function registerAndAccept(Request $request, string $token): RedirectResponse
    {
        if (auth()->check()) {
            return redirect()->route('invitations.show', ['token' => $token]);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        try {
            $result = $this->invitationService->registerAndAccept(
                $token,
                $validated['name'],
                $validated['password']
            );
        } catch (ValidationException $e) {
            throw $e;
        }

        $tenant = $result['tenant'];
        $user = $result['user'];

        tenancy()->initialize($tenant);

        if (! Auth::guard('web')->attempt([
            'email' => $user->email,
            'password' => $validated['password'],
        ])) {
            tenancy()->end();

            throw ValidationException::withMessages([
                'email' => ['Unable to sign in after registration.'],
            ]);
        }

        $request->session()->regenerate();
        $request->session()->put('tenant_id', $tenant->id);

        return redirect('/t/'.$tenant->id.'/dashboard')
            ->with('success', 'Account created and invitation accepted.');
    }
}
