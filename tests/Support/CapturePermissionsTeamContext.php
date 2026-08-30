<?php

declare(strict_types=1);

namespace Tests\Support;

use Closure;
use Illuminate\Http\Request;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpFoundation\Response;

/**
 * BK-115 Host RCA: capture Spatie/auth/tenant context immediately before permission middleware.
 */
final class CapturePermissionsTeamContext
{
    /** @var array<string, mixed>|null */
    public static ?array $snapshot = null;

    public function handle(Request $request, Closure $next): Response
    {
        $registrar = app(PermissionRegistrar::class);
        $tenant = function_exists('tenancy') ? tenancy()->tenant : null;

        self::$snapshot = [
            'tenant_id' => $tenant?->id,
            'permissions_team_id' => $registrar->getPermissionsTeamId(),
            'expected_team_id' => $tenant?->getTenantKey(),
            'authenticated_user_id' => $request->user()?->id,
            'route_tenant_label' => $request->route('tenant_label'),
            'can_setup_view_at_probe' => $request->user()
                ? (bool) $request->user()->can('tenant.setup.view')
                : false,
            'middleware_order_note' => 'probe runs after EnsureUserBelongsToTenant, before permission',
        ];

        return $next($request);
    }
}
