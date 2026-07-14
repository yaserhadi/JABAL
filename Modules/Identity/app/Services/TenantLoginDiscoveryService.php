<?php

namespace Modules\Identity\Services;

use Illuminate\Validation\ValidationException;
use Modules\Identity\Models\TenantUser;
use Modules\Tenancy\Models\Tenant;

/**
 * BK-064: Central login is discovery/routing only — never authenticates TenantUser centrally.
 */
class TenantLoginDiscoveryService
{
    public function resolveActiveTenant(?string $slug, ?string $email): Tenant
    {
        $slug = is_string($slug) ? trim($slug) : '';
        $email = is_string($email) ? trim($email) : '';

        if ($slug === '' && $email === '') {
            throw ValidationException::withMessages([
                'slug' => __('Enter a workspace slug or email to continue.'),
            ]);
        }

        if ($slug !== '') {
            $tenant = Tenant::query()->where('slug', $slug)->first();

            if (! $tenant || $tenant->status !== 'active') {
                throw ValidationException::withMessages([
                    'slug' => __('We could not find an active workspace for that slug.'),
                ]);
            }

            return $tenant;
        }

        $tenantUser = TenantUser::findForLogin($email);

        if (! $tenantUser) {
            throw ValidationException::withMessages([
                'email' => __('We could not find a workspace for that email.'),
            ]);
        }

        $tenant = $tenantUser->homeTenant();

        if (! $tenant || $tenant->status !== 'active') {
            throw ValidationException::withMessages([
                'email' => __('We could not find a workspace for that email.'),
            ]);
        }

        return $tenant;
    }
}
