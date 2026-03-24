<?php

namespace Modules\Workspaces\Models;

use App\Support\Audit\Auditable;
use App\Support\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Workspace — tenant-owned domain entity.
 *
 * A workspace is a container/organizer within a tenant, NOT a second tenancy layer
 * and NOT a replacement for Tenant.
 *
 * PHASE 3A:
 * - Uses tenant connection (jabal_tenant_shared)
 * - BelongsToTenant: query requires context; create requires context or explicit tenant_id
 */
class Workspace extends Model
{
    use Auditable;
    use BelongsToTenant;
    use HasUuids;

    protected $connection = 'tenant';

    protected $fillable = [
        'name',
        'slug',
    ];

    protected $casts = [];
}
