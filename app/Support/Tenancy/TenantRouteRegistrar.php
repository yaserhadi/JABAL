<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use Closure;
use Illuminate\Support\Facades\Route;

/**
 * Grouping primitives for Host-bound and Path-bound Tenant routes (BK-073).
 *
 * Modules own their route definitions; this registrar never centralizes controllers.
 * Wildcard {tenant_label} is NOT a Tenant resolver — no model binding, no DB lookup.
 */
final class TenantRouteRegistrar
{
    public function __construct(
        private readonly TenantAddressingProfile $addressing,
    ) {}

    /**
     * Bind a route group to the Platform Host (authority for discovery /login, /platform/*).
     */
    public function onPlatformHost(Closure $routes): void
    {
        $host = $this->addressing->platformHost();
        if ($host === '') {
            $routes();

            return;
        }

        Route::domain($host)->group($routes);
    }

    /**
     * Bind a route group to the Auth Host (authority for /auth/sso/callback).
     */
    public function onAuthHost(Closure $routes): void
    {
        $host = $this->addressing->authHost();
        if ($host === '') {
            $routes();

            return;
        }

        Route::domain($host)->group($routes);
    }

    /**
     * Bind Tenant Host routes under {tenant_label}.{platform_base_domain}.
     *
     * {tenant_label} matches the domain only — never route-model bound, never a resolver.
     * Reserved central labels (platform/auth/api/…) are excluded from the wildcard.
     */
    public function onTenantHost(Closure $routes): void
    {
        $base = $this->addressing->platformBaseDomain();
        if ($base === '') {
            return;
        }

        $reservedLabels = array_map(
            static fn (string $host): string => strtolower(trim(explode('.', $host)[0] ?? '')),
            array_merge(
                $this->addressing->centralHosts(),
                [
                    $this->addressing->platformHost(),
                    $this->addressing->authHost(),
                    $this->addressing->apiHost(),
                    $this->addressing->assetHost(),
                    $this->addressing->operationsHost(),
                ]
            )
        );

        $reserved = array_values(array_unique(array_filter(
            $reservedLabels,
            static fn (string $label): bool => $label !== '' && ! str_contains($label, '.')
        )));

        // Also exclude configured reserved handles (www, api, platform, …).
        $reserved = array_values(array_unique(array_merge(
            $reserved,
            array_map('strtolower', (array) config('tenant_handles.reserved', []))
        )));

        $reservedPattern = $reserved === []
            ? ''
            : '(?!'.implode('|', array_map(static fn (string $l): string => preg_quote($l, '/'), $reserved)).')';

        Route::domain('{tenant_label}.'.$base)
            ->where(['tenant_label' => $reservedPattern.'[a-z0-9]([a-z0-9-]*[a-z0-9])?'])
            ->group($routes);
    }

    public function addressing(): TenantAddressingProfile
    {
        return $this->addressing;
    }
}
