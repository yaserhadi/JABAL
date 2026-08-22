<?php

namespace Modules\Identity\Models;

use App\Support\Traits\BelongsToTenant;
use App\Support\Traits\ResolvesTenantStorageConnection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Tenancy\Models\Tenant;

/**
 * BK-043 / DEC-0011: Tenant-owned security policies (tenant data layer).
 *
 * Replaces the former central-only model. Central table remains deprecated (BK-038 cleanup).
 */
class TenantSecurityPolicy extends Model
{
    use BelongsToTenant;
    use HasUuids;
    use ResolvesTenantStorageConnection;

    protected $connection = 'tenant';

    protected $table = 'tenant_security_policies';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'tenant_id',
        'mfa_required',
        'mfa_grace_period_days',
        'password_policy',
        'session_idle_timeout',
        'authentication_policy',
        'mandatory_sso_enrollment',
        'sso_exception_closure_mode',
    ];

    protected $casts = [
        'mfa_required' => 'boolean',
        'mfa_grace_period_days' => 'integer',
        'password_policy' => 'array',
        'session_idle_timeout' => 'integer',
        'mandatory_sso_enrollment' => 'boolean',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
