<?php

namespace Modules\Identity\Models;

use App\Support\Traits\BelongsToTenant;
use App\Support\Traits\ResolvesTenantStorageConnection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Tenancy\Models\Tenant;

/**
 * BK-008: Federated identity binding — permanent key is (issuer, subject), not email.
 * WAVE-2: Linked / Login Verified / Ready evidence lives on this binding.
 */
class TenantUserIdentity extends Model
{
    use BelongsToTenant;
    use HasUuids;
    use ResolvesTenantStorageConnection;

    protected $connection = 'tenant';

    protected $table = 'tenant_user_identities';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'tenant_id',
        'user_id',
        'binding_role',
        'issuer',
        'subject',
        'email_at_link',
        'linked_at',
        'verification_status',
        'linked_idp_configuration_version_id',
        'login_verified_at',
        'ready_at',
        'ready_idp_configuration_version_id',
        'ready_canonical_email',
        'last_verification_failure_at',
        'last_verification_failure_reason',
        'superseded_at',
        'superseded_by_identity_id',
        'security_held_at',
        'security_held_reason',
    ];

    protected $casts = [
        'linked_at' => 'datetime',
        'login_verified_at' => 'datetime',
        'ready_at' => 'datetime',
        'last_verification_failure_at' => 'datetime',
        'superseded_at' => 'datetime',
        'security_held_at' => 'datetime',
    ];

    public const ROLE_CURRENT = 'current';

    public const ROLE_CANDIDATE = 'candidate';

    public const ROLE_HISTORICAL = 'historical';

    public const ROLE_SECURITY_HELD = 'security_held';

    public function isResolvableForLogin(): bool
    {
        return in_array($this->binding_role, [self::ROLE_CURRENT, self::ROLE_CANDIDATE], true)
            && $this->security_held_at === null;
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(TenantUser::class, 'user_id')->withoutGlobalScope('tenant');
    }
}
