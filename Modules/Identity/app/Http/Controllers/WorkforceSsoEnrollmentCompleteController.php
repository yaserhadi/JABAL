<?php

namespace Modules\Identity\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Identity\Exceptions\SsoSecurityException;
use Modules\Identity\Models\TenantUser;
use Modules\Identity\Models\WorkforceSsoEnrollmentInvitation;
use Modules\Identity\Services\WorkforceSsoEnrollmentAssociationService;
use Modules\Identity\Support\Sso\SsoBrowserBindingCookieFactory;
use Modules\Identity\Support\Sso\SsoFirstLinkAssurance;

/**
 * Tenant Host enrollment complete — associate identity as SSO Linked only; session unchanged.
 */
class WorkforceSsoEnrollmentCompleteController extends Controller
{
    public function __construct(
        protected WorkforceSsoEnrollmentAssociationService $association,
        protected SsoFirstLinkAssurance $firstLinkAssurance,
        protected \App\Http\Auth\TenantEntryUrlResolver $urls,
    ) {}

    public function __invoke(Request $request): Response|RedirectResponse
    {
        $tenant = tenancy()->tenant;
        if (! $tenant) {
            abort(404);
        }

        $user = $request->user();
        if (! $user instanceof TenantUser) {
            abort(403);
        }

        $reference = trim((string) $request->query('c', ''));
        if ($reference === '') {
            abort(404);
        }

        $continuationPeek = app(\Modules\Identity\Services\AuthenticationTransactionService::class)
            ->findEnrollmentContinuationByReference($reference);

        if ($continuationPeek === null) {
            abort(404);
        }

        $invitation = WorkforceSsoEnrollmentInvitation::query()
            ->where('tenant_id', $tenant->id)
            ->whereKey($continuationPeek->invitation_id)
            ->first();

        if (! $invitation instanceof WorkforceSsoEnrollmentInvitation) {
            abort(404);
        }

        $browserBinding = (string) $request->cookie(SsoBrowserBindingCookieFactory::TENANT_CONTINUATION, '');

        $sessionIdBefore = $request->session()->getId();

        try {
            $result = $this->association->associateFromWorkforceEnrollmentInvitation([
                'invitation' => $invitation,
                'authenticatedLocalActor' => $user,
                'continuationReference' => $reference,
                'browserBinding' => $browserBinding !== '' ? $browserBinding : null,
                'requestHost' => strtolower($request->getHost()),
            ]);
        } catch (SsoSecurityException $e) {
            if ($e->getMessage() === 'first_link_step_up_required') {
                $this->firstLinkAssurance->rememberReturnUrl($request->fullUrl());

                return redirect()->away(
                    $this->urls->namedRouteUrl('identity.sso.enrollment.step-up.password.show', $tenant)
                );
            }
            abort(403);
        }

        if ($request->session()->getId() !== $sessionIdBefore) {
            abort(500);
        }

        $request->session()->forget('sso.enrollment.invitation_id');

        Cookie::queue(
            SsoBrowserBindingCookieFactory::clear(SsoBrowserBindingCookieFactory::TENANT_CONTINUATION, $request->isSecure())
        );

        return Inertia::render('Security/SsoEnrollment/Complete', [
            'identity_link_id' => $result['identity']->id,
            'created' => $result['created'],
            'verification_status' => 'linked',
            'ready' => false,
            'message' => 'Company SSO linked. A normal Company SSO sign-in is still required to verify readiness.',
        ]);
    }
}
