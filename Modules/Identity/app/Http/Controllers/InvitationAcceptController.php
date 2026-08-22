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
use Modules\Identity\Models\TenantUser;
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
        if (! $invitation || $invitation->intended_user_id === null) {
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
        $intended = TenantUser::withoutGlobalScope('tenant')
            ->whereKey($invitation->intended_user_id)
            ->first();
        $user = auth()->user();
        $emailMatches = $user && strtolower($user->email) === strtolower($invitation->email);
        $isIntendedUser = $user && (string) $user->id === (string) $invitation->intended_user_id;

        return Inertia::render('Invitations/Accept', [
            'email' => $invitation->email,
            'intendedUserName' => $intended?->name,
            'tenant' => $tenant ? [
                'id' => $tenant->id,
                'name' => $tenant->name,
            ] : null,
            'isAuthenticated' => (bool) $user,
            'emailMatches' => $emailMatches,
            'isIntendedUser' => $isIntendedUser,
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

    /**
     * WAVE-3 GAP-004: Complete account for the already-created User (set Password; do not create User).
     */
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
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        try {
            $result = $this->invitationService->completeAccountInvitation(
                $invitation,
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
                'email' => ['Unable to sign in after account completion.'],
            ]);
        }

        $request->session()->regenerate();
        $request->session()->put('tenant_id', $tenant->id);

        return redirect('/t/'.$tenant->id.'/dashboard')
            ->with('success', 'Account completed and invitation accepted.');
    }

    protected function resolveSessionInvitation(Request $request): ?TenantInvitation
    {
        $id = $request->session()->get(self::SESSION_INVITATION_ID_KEY);
        if (! is_string($id) || $id === '') {
            return null;
        }

        $invitation = TenantInvitation::query()
            ->withoutGlobalScope('tenant')
            ->where('id', $id)
            ->pending()
            ->first();

        if (! $invitation || $invitation->intended_user_id === null) {
            return null;
        }

        return $invitation;
    }

    protected function forgetSessionInvitation(Request $request): void
    {
        $request->session()->forget(self::SESSION_INVITATION_ID_KEY);
    }
}
