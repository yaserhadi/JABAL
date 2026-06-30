<?php

namespace App\Auth;

use App\Support\Tenancy\TenantAuthLookup;
use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Resolve tenant users when tenancy is not initialized (session persistence, guest redirects).
 * When tenancy is active, normal scoped queries apply on the resolved storage connection.
 */
class TenantAwareUserProvider extends EloquentUserProvider
{
    public function retrieveById($identifier): ?Authenticatable
    {
        if (tenancy()->initialized && tenancy()->tenant) {
            return parent::retrieveById($identifier);
        }

        return app(TenantAuthLookup::class)->findUserById((string) $identifier);
    }

    public function retrieveByCredentials(array $credentials): ?Authenticatable
    {
        if (empty($credentials)) {
            return null;
        }

        $email = $credentials['email'] ?? null;

        if (! is_string($email) || $email === '') {
            return parent::retrieveByCredentials($credentials);
        }

        $user = app(TenantAuthLookup::class)->findUserByEmail($email);

        if (! $user) {
            return null;
        }

        if (tenancy()->initialized && tenancy()->tenant) {
            $query = $this->newModelQuery();

            foreach ($credentials as $key => $value) {
                if (str_contains($key, 'password')) {
                    continue;
                }

                $query->where($key, $value);
            }

            return $query->first();
        }

        return $user;
    }
}
