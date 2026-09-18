<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use Illuminate\Http\Request;
use Modules\Tenancy\Models\Tenant;

/**
 * BK-125 Wave 7 — Tenant-specific signed URL safety helper.
 *
 * Product signed URLs that are Tenant-specific MUST include tenant_id in the
 * signed parameter set. After handle reuse, a valid signature on a reused host
 * must still fail when tenant_id ≠ resolved Tenant ID.
 */
final class TenantBoundSignedUrl
{
    public static function assertMatchesResolvedTenant(Request $request): void
    {
        $claimed = $request->query('tenant_id');
        if (! is_string($claimed) || $claimed === '') {
            abort(403, 'Tenant-bound signed URL missing tenant_id.');
        }

        $resolved = tenancy()->tenant;
        if (! $resolved instanceof Tenant || (string) $resolved->id !== $claimed) {
            abort(403, 'Tenant-bound signed URL tenant mismatch.');
        }
    }
}
