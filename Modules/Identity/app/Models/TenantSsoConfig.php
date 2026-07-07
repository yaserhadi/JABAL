<?php

namespace Modules\Identity\Models;

use App\Support\Traits\BelongsToTenant;
use App\Support\Traits\ResolvesTenantStorageConnection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Tenancy\Models\Tenant;

/**
 * BK-008: Tenant-owned OIDC SSO configuration (tenant data layer).
 *
 * client_secret_encrypted is storage-only in schema-models; encryption/write-only API comes later.
 */
class TenantSsoConfig extends Model
{
    use BelongsToTenant;
    use HasUuids;
    use ResolvesTenantStorageConnection;

    protected $connection = 'tenant';

    protected $table = 'tenant_sso_config';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'tenant_id',
        'enabled',
        'disabled_by_entitlement',
        'provider_label',
        'issuer_url',
        'client_id',
        'client_secret_encrypted',
        'redirect_uri',
        'scopes',
    ];

    protected $hidden = [
        'client_secret_encrypted',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'disabled_by_entitlement' => 'boolean',
        'scopes' => 'array',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
