<?php

namespace Modules\Identity\Models;

use App\Support\Traits\BelongsToTenant;
use App\Support\Traits\ResolvesTenantStorageConnection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * BK-082: Immutable IdP configuration version (DEC-0024 D15 / D30 foundation).
 *
 * Material fields MUST NOT be edited after leaving draft. Active/superseded/disabled
 * rows only allow status lifecycle updates (e.g. active → superseded).
 */
class TenantSsoConfigVersion extends Model
{
    use BelongsToTenant;
    use HasUuids;
    use ResolvesTenantStorageConnection;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_SUPERSEDED = 'superseded';

    public const STATUS_DISABLED = 'disabled';

    protected $connection = 'tenant';

    protected $table = 'tenant_sso_config_versions';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'tenant_id',
        'config_id',
        'version_number',
        'status',
        'provider_label',
        'issuer_url',
        'client_id',
        'client_secret_encrypted',
        'redirect_uri',
        'jwks_uri',
        'logout_token_signing_algs',
        'scopes',
        'activated_at',
        'superseded_at',
    ];

    protected $hidden = [
        'client_secret_encrypted',
    ];

    protected $casts = [
        'scopes' => 'array',
        'logout_token_signing_algs' => 'array',
        'activated_at' => 'datetime',
        'superseded_at' => 'datetime',
        'version_number' => 'integer',
    ];

    protected static function booted(): void
    {
        static::updating(function (self $model): void {
            $originalStatus = $model->getOriginal('status');

            if ($originalStatus === null || $originalStatus === self::STATUS_DRAFT) {
                return;
            }

            $allowed = ['status', 'superseded_at', 'updated_at'];

            foreach (array_keys($model->getDirty()) as $attribute) {
                if (! in_array($attribute, $allowed, true)) {
                    throw new LogicException(
                        'IdP configuration version material fields are immutable once activated (DEC-0024 D15/D30).'
                    );
                }
            }
        });
    }

    public function config(): BelongsTo
    {
        return $this->belongsTo(TenantSsoConfig::class, 'config_id');
    }

    public function isBindable(): bool
    {
        return in_array($this->status, [self::STATUS_ACTIVE, self::STATUS_SUPERSEDED, self::STATUS_DISABLED], true);
    }
}
