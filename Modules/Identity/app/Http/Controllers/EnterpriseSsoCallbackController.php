<?php

namespace Modules\Identity\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Modules\Identity\Services\HostEnterpriseSsoCallbackService;

/**
 * BK-082 WS4: Auth Host Enterprise SSO callback (token exchange + Handoff mint only).
 *
 * Does not establish web sessions or Path Laravel-session PKCE materials.
 */
class EnterpriseSsoCallbackController extends Controller
{
    public function __construct(
        protected HostEnterpriseSsoCallbackService $callback,
    ) {}

    public function __invoke(Request $request): RedirectResponse
    {
        return $this->callback->handle($request);
    }
}
