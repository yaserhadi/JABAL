<?php

namespace Modules\Identity\Models;

use App\Support\Traits\BelongsToTenant;
use App\Support\Traits\ResolvesTenantStorageConnection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Tenancy\Models\Tenant;

/**
 * BK-099 Scenario B: Admin-issued Workforce SSO enrollment invitation.
 *
 * delivery_email is notification-only — never association authority.
 */
class WorkforceSsoEnrollmentInvitation extends Model
{
    use BelongsToTenant;
    use HasUuids;
    use ResolvesTenantStorageConnection;

    public const PURPOSE = 'workforce_sso_enrollment';

    protected $connection = 'tenant';

    protected $table = 'workforce_sso_enrollment_invitations';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'tenant_id',
        'intended_user_id',
        'membership_id',
        'sso_config_id',
        'sso_config_version_id',
        'tenant_host',
        'issued_by_user_id',
        'delivery_email',
        'token_hash',
        'expires_at',
        'cancelled_at',
        'consumed_at',
        'audit_correlation_id',
    ];

    protected $hidden = [
        'token_hash',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'consumed_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function intendedUser(): BelongsTo
    {
        return $this->belongsTo(TenantUser::class, 'intended_user_id')->withoutGlobalScope('tenant');
    }

    public function membership(): BelongsTo
    {
        return $this->belongsTo(Membership::class, 'membership_id')->withoutGlobalScope('tenant');
    }

    public function issuedBy(): BelongsTo
    {
        return $this->belongsTo(TenantUser::class, 'issued_by_user_id')->withoutGlobalScope('tenant');
    }

    public function isPending(): bool
    {
        return $this->cancelled_at === null
            && $this->consumed_at === null
            && $this->expires_at !== null
            && $this->expires_at->isFuture();
    }

    public function scopePending(Builder $query): Builder
    {
        return $query
            ->whereNull('cancelled_at')
            ->whereNull('consumed_at')
            ->where('expires_at', '>', now());
    }

    public function scopeForTenantHost(Builder $query, string $tenantHost): Builder
    {
        return $query->where('tenant_host', strtolower($tenantHost));
    }
}
