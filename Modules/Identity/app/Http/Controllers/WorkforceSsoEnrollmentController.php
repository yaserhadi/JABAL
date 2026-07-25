<?php

namespace Modules\Identity\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Identity\Exceptions\SsoSecurityException;
use Modules\Identity\Mail\WorkforceSsoEnrollmentInvitationMail;
use Modules\Identity\Models\Membership;
use Modules\Identity\Models\TenantUser;
use Modules\Identity\Models\WorkforceSsoEnrollmentInvitation;
use Modules\Identity\Services\WorkforceSsoEnrollmentInvitationService;
use Modules\Identity\Services\WorkforceSsoEnrollmentLoginResumeService;
use Modules\Identity\Services\WorkforceSsoEnrollmentOidcService;

/**
 * BK-099: Admin issue/list/cancel + employee invitation open / resume / start OIDC.
 */
class WorkforceSsoEnrollmentController extends Controller
{
    public function __construct(
        protected WorkforceSsoEnrollmentInvitationService $invitations,
        protected WorkforceSsoEnrollmentLoginResumeService $loginResumes,
        protected WorkforceSsoEnrollmentOidcService $oidc,
    ) {
        $this->middleware('permission:tenant.sso.view')->only(['index']);
        $this->middleware('permission:tenant.sso.configure')->only(['store', 'destroy']);
    }

    public function index(Request $request): Response
    {
        $tenant = tenancy()->tenant;
        if (! $tenant) {
            abort(404);
        }

        $rows = WorkforceSsoEnrollmentInvitation::query()
            ->where('tenant_id', $tenant->id)
            ->orderByDesc('created_at')
            ->limit(100)
            ->get()
            ->map(fn (WorkforceSsoEnrollmentInvitation $inv) => [
                'id' => $inv->id,
                'intended_user_id' => $inv->intended_user_id,
                'delivery_email' => $inv->delivery_email,
                'expires_at' => optional($inv->expires_at)?->toIso8601String(),
                'cancelled_at' => optional($inv->cancelled_at)?->toIso8601String(),
                'consumed_at' => optional($inv->consumed_at)?->toIso8601String(),
                'pending' => $inv->isPending(),
            ]);

        return Inertia::render('Security/SsoEnrollment/Index', [
            'invitations' => $rows,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $tenant = tenancy()->tenant;
        if (! $tenant) {
            abort(404);
        }

        $validated = $request->validate([
            'intended_user_id' => 'required|uuid',
            'delivery_email' => 'required|email|max:255',
        ]);

        /** @var TenantUser $admin */
        $admin = $request->user();
        $intended = TenantUser::query()
            ->withoutGlobalScope('tenant')
            ->where('tenant_id', $tenant->id)
            ->whereKey($validated['intended_user_id'])
            ->firstOrFail();

        $membership = Membership::query()
            ->where('tenant_id', $tenant->id)
            ->where('user_id', $intended->id)
            ->where('status', 'active')
            ->firstOrFail();

        try {
            $created = $this->invitations->createInvitation(
                $tenant,
                $admin,
                $intended,
                $membership,
                $validated['delivery_email'],
                strtolower($request->getHost()),
            );
        } catch (SsoSecurityException $e) {
            throw ValidationException::withMessages([
                'intended_user_id' => $e->getMessage(),
            ]);
        }

        $url = url('/security/sso/enrollment/invitations/'.$created['plainToken']);

        Mail::to($validated['delivery_email'])->send(new WorkforceSsoEnrollmentInvitationMail(
            $tenant,
            $admin->name ?? 'Administrator',
            $url,
            $created['invitation']->expires_at,
        ));

        return redirect()
            ->route('identity.sso.enrollments.index')
            ->with('success', 'Workforce SSO enrollment invitation issued.');
    }

    public function destroy(Request $request, string $invitationId): RedirectResponse
    {
        $tenant = tenancy()->tenant;
        if (! $tenant) {
            abort(404);
        }

        $invitation = WorkforceSsoEnrollmentInvitation::query()
            ->where('tenant_id', $tenant->id)
            ->whereKey($invitationId)
            ->firstOrFail();

        /** @var TenantUser $actor */
        $actor = $request->user();

        try {
            $this->invitations->cancelInvitation($tenant, $invitation, $actor);
        } catch (SsoSecurityException $e) {
            throw ValidationException::withMessages([
                'invitation' => $e->getMessage(),
            ]);
        }

        return redirect()
            ->route('identity.sso.enrollments.index')
            ->with('success', 'Invitation cancelled.');
    }

    /**
     * Employee opens invitation token (guest or auth).
     */
    public function openInvitation(Request $request): RedirectResponse|Response
    {
        $tenant = tenancy()->tenant;
        if (! $tenant) {
            abort(404);
        }

        $token = (string) $request->route('enrollment_token', '');
        $invitation = $this->invitations->findValidByToken($tenant, $token, strtolower($request->getHost()));
        if (! $invitation) {
            abort(404);
        }

        $user = $request->user();
        if (! $user instanceof TenantUser) {
            $issued = $this->loginResumes->issueResume($invitation, strtolower($request->getHost()), $request);
            $loginUrl = app(\App\Http\Auth\TenantEntryUrlResolver::class)->loginUrl($tenant);

            return redirect()->to($loginUrl.'?'.http_build_query([
                'email' => $invitation->delivery_email,
            ]))->withCookies([
                $issued['resumeCookie'],
                $issued['bindingCookie'],
            ]);
        }

        try {
            $this->invitations->assertActorMatchesInvitation($user, $invitation);
        } catch (SsoSecurityException) {
            return Inertia::render('Security/SsoEnrollment/Denied', [
                'reason' => 'wrong_account',
            ]);
        }

        $request->session()->put('sso.enrollment.invitation_id', (string) $invitation->id);

        return Inertia::render('Security/SsoEnrollment/Ready', [
            'invitation_id' => $invitation->id,
            'start_url' => url('/security/sso/enrollment/start'),
            'token' => $token,
        ]);
    }

    public function resume(Request $request): RedirectResponse|Response
    {
        $tenant = tenancy()->tenant;
        if (! $tenant) {
            abort(404);
        }

        $user = $request->user();
        if (! $user instanceof TenantUser) {
            abort(403);
        }

        $token = $this->loginResumes->peekTokenFromRequest($request);
        $invitation = $this->loginResumes->consumeAndValidate(
            $token,
            $request,
            strtolower($request->getHost()),
            $tenant,
        );

        if (! $invitation) {
            abort(404);
        }

        try {
            $this->invitations->assertActorMatchesInvitation($user, $invitation);
        } catch (SsoSecurityException) {
            return Inertia::render('Security/SsoEnrollment/Denied', [
                'reason' => 'wrong_account',
            ]);
        }

        // Re-issue a short-lived opaque path via invitation token is unavailable (hash only).
        // Authenticated resume proceeds directly to Ready using invitation id in session gate.
        $request->session()->put('sso.enrollment.invitation_id', (string) $invitation->id);

        return Inertia::render('Security/SsoEnrollment/Ready', [
            'invitation_id' => $invitation->id,
            'start_url' => url('/security/sso/enrollment/start'),
            'token' => null,
        ]);
    }

    public function start(Request $request): RedirectResponse
    {
        $tenant = tenancy()->tenant;
        if (! $tenant) {
            abort(404);
        }

        /** @var TenantUser $user */
        $user = $request->user();
        if (! $user instanceof TenantUser) {
            abort(403);
        }

        $validated = $request->validate([
            'invitation_id' => 'required|uuid',
            // Client must NOT submit target_user_id — ignore if present.
        ]);

        $invitation = WorkforceSsoEnrollmentInvitation::query()
            ->where('tenant_id', $tenant->id)
            ->whereKey($validated['invitation_id'])
            ->firstOrFail();

        // Prefer session-bound invitation after resume; also allow open-invitation path when session matches.
        $sessionInvitationId = $request->session()->get('sso.enrollment.invitation_id');
        if (is_string($sessionInvitationId) && $sessionInvitationId !== '' && $sessionInvitationId !== (string) $invitation->id) {
            abort(403);
        }

        try {
            return $this->oidc->startEnrollmentOidc($tenant, $invitation, $user, $request);
        } catch (SsoSecurityException $e) {
            throw ValidationException::withMessages([
                'invitation_id' => $e->getMessage(),
            ]);
        }
    }
}
