<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use Illuminate\Http\Request;

/**
 * BK-125 Wave 5 / CONFLICT-D: detect Platform Operator URI surface.
 *
 * PATH: plane is identified by the /platform/* path prefix on the shared host.
 * PATH_HOST: plane is identified by the Platform Host (root paths; no /platform prefix).
 */
final class PlatformOperatorSurface
{
    public function __construct(
        private readonly TenantAddressingProfile $addressing,
    ) {}

    public function matches(Request $request): bool
    {
        if ($this->addressing->isPathHost()) {
            $platformHost = strtolower($this->addressing->platformHost());
            if ($platformHost === '') {
                return false;
            }

            return strtolower($request->getHost()) === $platformHost;
        }

        return $request->is('platform') || $request->is('platform/*');
    }
}
