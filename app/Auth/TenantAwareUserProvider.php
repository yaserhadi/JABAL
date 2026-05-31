<?php

namespace App\Auth;

use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Resolve tenant users when tenancy is not initialized (session persistence, guest redirects).
 * When tenancy is active, normal scoped queries apply.
 */
class TenantAwareUserProvider extends EloquentUserProvider
{
    public function retrieveById($identifier): ?Authenticatable
    {
        $model = $this->createModel();

        if (! tenancy()->initialized || ! tenancy()->tenant) {
            return $model->newQuery()
                ->withoutGlobalScope('tenant')
                ->where($model->getAuthIdentifierName(), $identifier)
                ->first();
        }

        return parent::retrieveById($identifier);
    }
}
