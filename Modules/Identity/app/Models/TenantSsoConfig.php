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
 * BK-008 / BK-082 / BK-098: Tenant-owned OIDC SSO configuration (tenant data layer).
 *
 * Material IdP settings are versioned via TenantSsoConfigVersion (active_version_id).
 * Operational flags and WS8 rollout/kill state remain on this parent row.
 *
 * BK-098: Do not add operational credential_provider / credential_reference on this
 * parent. Version rows own reference metadata; parent holds active_version_id only.
 */
class TenantSsoConfig extends Model
{
    use BelongsToTenant;
    use HasUuids;
    use ResolvesTenantStorageConnection;

    public const ROLLOUT_DISABLED = 'disabled';

    public const ROLLOUT_TEST_ONLY = 'test_only';

    public const ROLLOUT_PILOT = 'pilot';

    public const ROLLOUT_ENABLED = 'enabled';

    public const ROLLOUT_PAUSED = 'paused';

    public const ROLLOUT_SECURITY_DISABLED = 'security_disabled';

    /** @var list<string> */
    public const ROLLOUT_STATES = [
        self::ROLLOUT_DISABLED,
        self::ROLLOUT_TEST_ONLY,
        self::ROLLOUT_PILOT,
        self::ROLLOUT_ENABLED,
        self::ROLLOUT_PAUSED,
        self::ROLLOUT_SECURITY_DISABLED,
    ];

    protected $connection = 'tenant';

    protected $table = 'tenant_sso_config';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'tenant_id',
        'enabled',
        'disabled_by_entitlement',
        'rollout_state',
        'security_disabled_at',
        'security_disable_reason',
        'pilot_user_id_hashes',
        'provider_label',
        'issuer_url',
        'client_id',
        'redirect_uri',
        'jwks_uri',
        'logout_token_signing_algs',
        'scopes',
        'active_version_id',
        'pending_version_id',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'disabled_by_entitlement' => 'boolean',
        'scopes' => 'array',
        'logout_token_signing_algs' => 'array',
        'pilot_user_id_hashes' => 'array',
        'security_disabled_at' => 'datetime',
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

    public function pendingVersion(): BelongsTo
    {
        return $this->belongsTo(TenantSsoConfigVersion::class, 'pending_version_id');
    }
}
