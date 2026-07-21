<?php

namespace Modules\Identity\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Modules\Identity\Exceptions\SsoSecurityException;
use Modules\Identity\Services\HostEnterpriseSsoInitiationService;

/**
 * BK-082 WS3: Auth Host Enterprise SSO initiate (binding cookie + IdP authorize redirect).
 *
 * Distinct from callback — no token exchange, Handoff, or session issuance.
 */
class EnterpriseSsoInitiateController extends Controller
{
    public function __construct(
        protected HostEnterpriseSsoInitiationService $initiation,
    ) {}

    public function __invoke(Request $request): RedirectResponse
    {
        try {
            return $this->initiation->initiateOnAuthHost($request);
        } catch (SsoSecurityException) {
            abort(404);
        }
    }
}
