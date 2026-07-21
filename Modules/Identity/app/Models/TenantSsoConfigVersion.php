<?php

namespace Modules\Identity\Models;

use App\Support\Traits\BelongsToTenant;
use App\Support\Traits\ResolvesTenantStorageConnection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * BK-082: Immutable IdP configuration version (DEC-0024 D15 / D30 / WS8 lifecycle).
 *
 * Material fields MUST NOT be edited after leaving draft. Non-draft rows only allow
 * lifecycle column updates (status timestamps / disable reason / secret revoke).
 */
class TenantSsoConfigVersion extends Model
{
    use BelongsToTenant;
    use HasUuids;
    use ResolvesTenantStorageConnection;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_VALIDATED = 'validated';

    public const STATUS_TEST_ONLY = 'test_only';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_DISABLED = 'disabled';

    public const STATUS_SUPERSEDED = 'superseded';

    /** @var list<string> */
    public const LIFECYCLE_STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_VALIDATED,
        self::STATUS_TEST_ONLY,
        self::STATUS_APPROVED,
        self::STATUS_ACTIVE,
        self::STATUS_DISABLED,
        self::STATUS_SUPERSEDED,
    ];

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
        'validated_at',
        'approved_at',
        'superseded_at',
        'disabled_at',
        'secret_revoked_at',
        'disable_reason',
    ];

    protected $hidden = [
        'client_secret_encrypted',
    ];

    protected $casts = [
        'scopes' => 'array',
        'logout_token_signing_algs' => 'array',
        'activated_at' => 'datetime',
        'validated_at' => 'datetime',
        'approved_at' => 'datetime',
        'superseded_at' => 'datetime',
        'disabled_at' => 'datetime',
        'secret_revoked_at' => 'datetime',
        'version_number' => 'integer',
    ];

    protected static function booted(): void
    {
        static::updating(function (self $model): void {
            $originalStatus = $model->getOriginal('status');

            if ($originalStatus === null || $originalStatus === self::STATUS_DRAFT) {
                return;
            }

            $allowed = [
                'status',
                'superseded_at',
                'validated_at',
                'approved_at',
                'disabled_at',
                'secret_revoked_at',
                'disable_reason',
                'activated_at',
                'updated_at',
            ];

            foreach (array_keys($model->getDirty()) as $attribute) {
                if (! in_array($attribute, $allowed, true)) {
                    throw new LogicException(
                        'IdP configuration version material fields are immutable once leaving draft (DEC-0024 D15/D30).'
                    );
                }
            }
        });
    }

    public function config(): BelongsTo
    {
        return $this->belongsTo(TenantSsoConfig::class, 'config_id');
    }

    public function isBindableForInFlight(): bool
    {
        return in_array($this->status, [
            self::STATUS_ACTIVE,
            self::STATUS_SUPERSEDED,
            self::STATUS_DISABLED,
            self::STATUS_TEST_ONLY,
        ], true);
    }

    public function mayServeNewProductionLogin(): bool
    {
        return $this->status === self::STATUS_ACTIVE
            && $this->secret_revoked_at === null;
    }

    public function isTestOnly(): bool
    {
        return $this->status === self::STATUS_TEST_ONLY;
    }
}
