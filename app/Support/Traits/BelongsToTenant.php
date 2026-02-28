<?php

namespace App\Support\Traits;

use Illuminate\Database\Eloquent\Builder;
use RuntimeException;

/**
 * Apply ONLY to tenant-owned domain models.
 * DO NOT apply to central models.
 *
 * PHASE 2 LOCK:
 * - Query: ALWAYS requires tenant context (no exceptions)
 * - Create: Requires context OR explicit tenant_id (allows console/seeders)
 * - NO withoutTenantScope() escape hatch — use explicit withoutGlobalScope('tenant')
 */
trait BelongsToTenant
{
    protected static function bootBelongsToTenant(): void
    {
        static::addGlobalScope('tenant', function (Builder $builder) {
            if (! tenancy()->initialized || ! tenancy()->tenant) {
                throw new RuntimeException(
                    'Cannot query tenant-scoped model ['.static::class.'] without tenant context.'
                );
            }
            $builder->where($builder->getModel()->getTable().'.tenant_id', tenancy()->tenant->id);
        });

        static::creating(function ($model) {
            if ($model->tenant_id) {
                return;
            }

            if (tenancy()->initialized && tenancy()->tenant) {
                $model->tenant_id = tenancy()->tenant->id;

                return;
            }

            throw new RuntimeException(
                'Cannot create tenant-scoped model ['.get_class($model).'] without tenant context or explicit tenant_id.'
            );
        });
    }

    public function tenant()
    {
        return $this->belongsTo(\Modules\Tenancy\Models\Tenant::class);
    }
}
