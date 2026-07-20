<?php

namespace Modules\Identity\Models;

use App\Support\Traits\BelongsToTenant;
use App\Support\Traits\ResolvesTenantStorageConnection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Tenancy\Models\Tenant;

/**
 * BK-008 / BK-082: Tenant-owned OIDC SSO configuration (tenant data layer).
 *
 * Material IdP settings are versioned via TenantSsoConfigVersion (active_version_id).
 * Operational flags (enabled / disabled_by_entitlement) remain on this parent row.
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
        'jwks_uri',
        'logout_token_signing_algs',
        'scopes',
        'active_version_id',
    ];

    protected $hidden = [
        'client_secret_encrypted',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'disabled_by_entitlement' => 'boolean',
        'scopes' => 'array',
        'logout_token_signing_algs' => 'array',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function versions(): HasMany
    {
        return $this->hasMany(TenantSsoConfigVersion::class, 'config_id');
    }

    public function activeVersion(): BelongsTo
    {
        return $this->belongsTo(TenantSsoConfigVersion::class, 'active_version_id');
    }
}
