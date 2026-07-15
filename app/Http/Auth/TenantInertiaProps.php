<?php

namespace App\Http\Auth;

use Modules\Tenancy\Models\Tenant;

/**
 * Shared Inertia tenant payload for slug-canonical navigation (BK-066).
 */
final class TenantInertiaProps
{
    /**
     * @return array{id: string, name: string|null, slug: string|null, entryKey: string}
     */
    public static function from(Tenant $tenant): array
    {
        return [
            'id' => $tenant->id,
            'name' => $tenant->name,
            'slug' => $tenant->slug,
            'entryKey' => $tenant->entryKey(),
        ];
    }
}
