<?php

namespace Modules\Identity\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Modules\Identity\Services\SsoBackChannelLogoutService;

/**
 * BK-082 WS7: Auth Host OIDC Back-Channel Logout endpoint (D26).
 */
class EnterpriseSsoBackChannelLogoutController extends Controller
{
    public function __construct(
        protected SsoBackChannelLogoutService $backChannelLogout,
    ) {}

    public function __invoke(Request $request): Response
    {
        $logoutToken = (string) $request->input('logout_token', '');
        $tenantId = (string) $request->query('tenant', $request->input('tenant', ''));

        $result = $this->backChannelLogout->handle($logoutToken, $tenantId);

        $status = $result['ok'] ? 200 : (($result['status'] >= 500) ? 500 : 400);

        return response('', $status)->withHeaders([
            'Cache-Control' => 'no-store',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
