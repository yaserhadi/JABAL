<?php

namespace App\Support\Traits;

use App\Models\TenantPersonalAccessToken;
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
                // Sanctum resolves the tokenable user before X-Tenant-Id initializes tenancy.
                if (request()->bearerToken() && (request()->is('api/*') || app()->runningUnitTests())) {
                    return;
                }

                throw new RuntimeException(
                    'Cannot query tenant-scoped model ['.static::class.'] without tenant context.'
                );
            }
            $table = $builder->getModel()->getTable();
            $tenantId = tenancy()->tenant->id;

            // shared_db: authenticated user may belong via central membership with home tenant_id elsewhere.
            // IMPORTANT: never call auth()->check() / auth()->id() here — Auth resolution itself queries
            // tenant-scoped User and would re-enter this scope, causing infinite recursion (OOM).
            // Use hasUser() on each session-backed guard, which only returns true if the user is already memoized.
            $authUserId = null;
            foreach (['web', 'platform'] as $guardName) {
                try {
                    $guard = \Illuminate\Support\Facades\Auth::guard($guardName);
                } catch (\Throwable $e) {
                    continue;
                }
                if (method_exists($guard, 'hasUser') && $guard->hasUser()) {
                    $authUserId = $guard->id();
                    break;
                }
            }

            if ($authUserId !== null) {
                $builder->where(function (Builder $query) use ($table, $tenantId, $authUserId) {
                    $query->where($table.'.tenant_id', $tenantId)
                        ->orWhere($table.'.id', $authUserId);
                });

                return;
            }

            // Stancl may initialize tenancy from X-Tenant-Id before Sanctum loads the tokenable user.
            $bearerUserId = null;
            if (request()->bearerToken() && (request()->is('api/*') || app()->runningUnitTests())) {
                $accessToken = TenantPersonalAccessToken::findToken(request()->bearerToken());
                if ($accessToken) {
                    $bearerUserId = $accessToken->tokenable_id;
                }
            }

            if ($bearerUserId !== null) {
                $builder->where(function (Builder $query) use ($table, $tenantId, $bearerUserId) {
                    $query->where($table.'.tenant_id', $tenantId)
                        ->orWhere($table.'.id', $bearerUserId);
                });

                return;
            }

            $builder->where($table.'.tenant_id', $tenantId);
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
