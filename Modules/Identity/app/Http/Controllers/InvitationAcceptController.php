<?php

namespace Modules\Identity\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Modules\Identity\Models\TenantInvitation;
use Modules\Identity\Services\TenantInvitationService;
use Modules\Tenancy\Models\Tenant;

class InvitationAcceptController extends Controller
{
    public const SESSION_INVITATION_ID_KEY = 'tenant_invitation_id';

    public function __construct(
        private TenantInvitationService $invitationService
    ) {}

    /**
     * Entry point from shared invite links; stores invitation id in session and redirects to tokenless URL.
     */
    public function bootstrap(string $token): RedirectResponse
    {
        $invitation = $this->invitationService->findValidByToken($token);
        if (! $invitation) {
            abort(404, 'This invitation is invalid or has expired.');
        }

        session([self::SESSION_INVITATION_ID_KEY => $invitation->id]);

        return redirect()->route('invitations.show');
    }

    public function show(Request $request): InertiaResponse|RedirectResponse
    {
        $invitation = $this->resolveSessionInvitation($request);
        if (! $invitation) {
            abort(404, 'This invitation is invalid or has expired.');
        }

        $tenant = Tenant::query()->find($invitation->tenant_id);
        $user = auth()->user();
        $emailMatches = $user && strtolower($user->email) === strtolower($invitation->email);

        return Inertia::render('Invitations/Accept', [
            'email' => $invitation->email,
            'tenant' => $tenant ? [
                'id' => $tenant->id,
                'name' => $tenant->name,
            ] : null,
            'isAuthenticated' => (bool) $user,
            'emailMatches' => $emailMatches,
        ]);
    }

    public function accept(Request $request): RedirectResponse
    {
        $invitation = $this->resolveSessionInvitation($request);
        if (! $invitation) {
            abort(404, 'This invitation is invalid or has expired.');
        }

        $user = auth()->user();
        if (! $user) {
            return redirect()->route('login')->with('url.intended', route('invitations.show'));
        }

        try {
            $membership = $this->invitationService->acceptInvitationRecord($invitation, $user);
        } catch (ValidationException $e) {
            throw $e;
        }

        $this->forgetSessionInvitation($request);

        $tenant = Tenant::query()->findOrFail($membership->tenant_id);

        return redirect('/t/'.$tenant->id.'/dashboard')
            ->with('success', 'You have joined the workspace.');
    }

    public function registerAndAccept(Request $request): RedirectResponse
    {
        if (auth()->check()) {
            return redirect()->route('invitations.show');
        }

        $invitation = $this->resolveSessionInvitation($request);
        if (! $invitation) {
            abort(404, 'This invitation is invalid or has expired.');
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        try {
            $result = $this->invitationService->registerAndAcceptInvitation(
                $invitation,
                $validated['name'],
                $validated['password']
            );
        } catch (ValidationException $e) {
            throw $e;
        }

        $this->forgetSessionInvitation($request);

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

    protected function resolveSessionInvitation(Request $request): ?TenantInvitation
    {
        $id = $request->session()->get(self::SESSION_INVITATION_ID_KEY);
        if (! is_string($id) || $id === '') {
            return null;
        }

        return TenantInvitation::query()
            ->withoutGlobalScope('tenant')
            ->where('id', $id)
            ->pending()
            ->first();
    }

    protected function forgetSessionInvitation(Request $request): void
    {
        $request->session()->forget(self::SESSION_INVITATION_ID_KEY);
    }
}
