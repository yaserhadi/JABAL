<?php

namespace Modules\Tenancy\Services;

use Modules\Identity\Models\Membership;
use Modules\Identity\Models\TenantUser;
use Modules\Tenancy\Models\Tenant;

/**
 * Detail-only Application Owner lookup (BK-069). Never use from tenant list queries.
 */
final class PlatformTenantApplicationOwnerResolver
{
    /**
     * @return array{id: string, name: string, email: string}|null
     */
    public function resolve(Tenant $tenant): ?array
    {
        tenancy()->initialize($tenant);

        try {
            $membership = Membership::query()
                ->withoutGlobalScope('tenant')
                ->where('tenant_id', $tenant->id)
                ->where('membership_type', 'owner')
                ->where('status', 'active')
                ->first();

            if ($membership === null) {
                return null;
            }

            $user = TenantUser::withoutGlobalScope('tenant')->find($membership->user_id);
            if ($user === null) {
                return null;
            }

            return [
                'id' => (string) $user->id,
                'name' => (string) $user->name,
                'email' => (string) $user->email,
            ];
        } finally {
            tenancy()->end();
        }
    }
}
