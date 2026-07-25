<?php

namespace Modules\Identity\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Identity\Exceptions\SsoSecurityException;
use Modules\Identity\Models\TenantUser;
use Modules\Identity\Models\WorkforceSsoEnrollmentInvitation;
use Modules\Identity\Services\WorkforceSsoEnrollmentAssociationService;
use Modules\Identity\Support\Sso\SsoBrowserBindingCookieFactory;

/**
 * BK-099: Tenant Host enrollment complete — associate identity; session unchanged.
 */
class WorkforceSsoEnrollmentCompleteController extends Controller
{
    public function __construct(
        protected WorkforceSsoEnrollmentAssociationService $association,
    ) {}

    public function __invoke(Request $request): Response
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

        // Capture session id before associate to prove non-mutation in product path.
        $sessionIdBefore = $request->session()->getId();

        try {
            $result = $this->association->associateFromWorkforceEnrollmentInvitation([
                'invitation' => $invitation,
                'authenticatedLocalActor' => $user,
                'continuationReference' => $reference,
                'browserBinding' => $browserBinding !== '' ? $browserBinding : null,
                'requestHost' => strtolower($request->getHost()),
            ]);
        } catch (SsoSecurityException) {
            abort(403);
        }

        // MUST NOT login / regenerate — assert session id unchanged.
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
            'message' => 'Enterprise SSO is now available for future sign-in.',
        ]);
    }
}
