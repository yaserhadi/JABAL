<?php

namespace Modules\Identity\Http\Controllers;

use App\Http\Auth\TenantEntryUrlResolver;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Modules\Identity\Exceptions\SsoSecurityException;
use Modules\Identity\Services\HostEnterpriseSsoInitiationService;
use Modules\Tenancy\Models\Tenant;

/**
 * BK-082 WS3/WS9: Tenant Host Enterprise SSO start (continuation cookie + Auth Host redirect).
 */
class EnterpriseSsoStartController extends Controller
{
    public function __construct(
        protected HostEnterpriseSsoInitiationService $initiation,
        protected TenantEntryUrlResolver $entryUrls,
    ) {}

    public function __invoke(Request $request): RedirectResponse
    {
        $tenant = tenancy()->tenant instanceof Tenant ? tenancy()->tenant : null;
        if (! $tenant instanceof Tenant) {
            abort(404);
        }

        try {
            return $this->initiation->startOnTenantHost($tenant, $request);
        } catch (SsoSecurityException) {
            // Generic, non-enumerating failure — password form remains available; no auto-fallback.
            return redirect()
                ->to($this->entryUrls->loginUrl($tenant))
                ->withErrors(['email' => __('Single sign-on is not available for this organization.')]);
        }
    }
}
