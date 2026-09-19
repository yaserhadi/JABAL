<?php

namespace Modules\Identity\Http\Controllers;

use App\Http\Auth\TenantEntryUrlResolver;
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
        private TenantInvitationService $invitationService,
        private TenantEntryUrlResolver $tenantEntryUrls,
    ) {}

    /**
     * Entry point from shared invite links; stores invitation id in session and redirects to tokenless URL.
     * Known tokens that are no longer pending still enter the guest Accept surface with lifecycle messaging.
     * Unknown tokens remain fail-closed (404).
     */
    public function bootstrap(string $token): RedirectResponse
    {
        $invitation = $this->invitationService->findByToken($token);
        if (! $invitation || $invitation->intended_user_id === null) {
            abort(404, 'This invitation is invalid or has expired.');
        }

        session([self::SESSION_INVITATION_ID_KEY => $invitation->id]);

        return redirect()->route('invitations.show');
    }

    public function show(Request $request): InertiaResponse|RedirectResponse
    {
        $resolved = $this->resolveSessionInvitationState($request);
        if ($resolved === null) {
            abort(404, 'This invitation is invalid or has expired.');
        }

        $invitation = $resolved['invitation'];
        $lifecycle = $resolved['lifecycle'];

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
            'invitationTenant' => $tenant ? [
                'id' => $tenant->id,
                'name' => $tenant->name,
            ] : null,
            // Do not share page.props.tenant — that would drive AppLayout tenant chrome.
            'isAuthenticated' => (bool) $user,
            'emailMatches' => (bool) $emailMatches,
            'isIntendedUser' => (bool) $isIntendedUser,
            'lifecycleStatus' => $lifecycle['status'],
            'lifecycleMessage' => $lifecycle['message'],
        ]);
    }

    public function accept(Request $request): RedirectResponse
    {
        $resolved = $this->resolveSessionInvitationState($request);
        if ($resolved === null || $resolved['lifecycle']['status'] !== 'pending') {
            return $this->lifecycleRedirect($resolved);
        }

        $invitation = $resolved['invitation'];

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

        return redirect()->to($this->tenantEntryUrls->dashboardUrl($tenant))
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

        $resolved = $this->resolveSessionInvitationState($request);
        if ($resolved === null || $resolved['lifecycle']['status'] !== 'pending') {
            return $this->lifecycleRedirect($resolved);
        }

        $invitation = $resolved['invitation'];

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

        return redirect()->to($this->tenantEntryUrls->dashboardUrl($tenant))
            ->with('success', 'Account completed and invitation accepted.');
    }

    /**
     * @return array{invitation: TenantInvitation, lifecycle: array{status: string, message: ?string}}|null
     */
    protected function resolveSessionInvitationState(Request $request): ?array
    {
        $id = $request->session()->get(self::SESSION_INVITATION_ID_KEY);
        if (! is_string($id) || $id === '') {
            return null;
        }

        $invitation = TenantInvitation::query()
            ->withoutGlobalScope('tenant')
            ->where('id', $id)
            ->first();

        if (! $invitation || $invitation->intended_user_id === null) {
            return null;
        }

        return [
            'invitation' => $invitation,
            'lifecycle' => $this->lifecycleFor($invitation),
        ];
    }

    /**
     * @return array{status: string, message: ?string}
     */
    protected function lifecycleFor(TenantInvitation $invitation): array
    {
        if ($invitation->accepted_at !== null) {
            return [
                'status' => 'accepted',
                'message' => 'This invitation has already been accepted. Sign in to continue.',
            ];
        }

        if ($invitation->revoked_at !== null) {
            return [
                'status' => 'revoked',
                'message' => 'This invitation was revoked. Ask an administrator for a new invite.',
            ];
        }

        if ($invitation->expires_at !== null && $invitation->expires_at->isPast()) {
            return [
                'status' => 'expired',
                'message' => 'This invitation has expired. Ask an administrator to send a new invite.',
            ];
        }

        return [
            'status' => 'pending',
            'message' => null,
        ];
    }

    /**
     * @param  array{invitation: TenantInvitation, lifecycle: array{status: string, message: ?string}}|null  $resolved
     */
    protected function lifecycleRedirect(?array $resolved): RedirectResponse
    {
        if ($resolved === null) {
            abort(404, 'This invitation is invalid or has expired.');
        }

        return redirect()
            ->route('invitations.show')
            ->with('warning', $resolved['lifecycle']['message']);
    }

    protected function forgetSessionInvitation(Request $request): void
    {
        $request->session()->forget(self::SESSION_INVITATION_ID_KEY);
    }
}
