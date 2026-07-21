<?php

namespace Modules\Identity\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Modules\Identity\Services\HostEnterpriseSsoHandoffService;

/**
 * BK-082 WS5: Tenant Host Enterprise SSO Handoff consumer.
 */
class EnterpriseSsoHandoffController extends Controller
{
    public function __construct(
        protected HostEnterpriseSsoHandoffService $handoff,
    ) {}

    public function __invoke(Request $request): RedirectResponse
    {
        return $this->handoff->handle($request);
    }
}
