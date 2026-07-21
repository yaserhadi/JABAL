<?php

namespace Modules\Identity\Models;

use App\Support\Traits\BelongsToTenant;
use App\Support\Traits\ResolvesTenantStorageConnection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserSession extends Model
{
    use BelongsToTenant;
    use HasUuids;
    use ResolvesTenantStorageConnection;

    protected $connection = 'tenant';

    protected $table = 'user_sessions';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'tenant_id',
        'user_id',
        'session_id',
        'idp_sid',
        'idp_issuer',
        'identity_link_id',
        'idp_configuration_version_id',
        'correlation_id',
        'ip_address',
        'user_agent',
        'device_label',
        'last_activity_at',
        'logged_in_at',
        'revoked_at',
    ];

    protected $casts = [
        'last_activity_at' => 'datetime',
        'logged_in_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(TenantUser::class, 'user_id')->withoutGlobalScope('tenant');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('revoked_at');
    }

    public function scopeForUser(Builder $query, string $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    public function scopeExpired(Builder $query, int $olderThanDays): Builder
    {
        return $query->where('last_activity_at', '<', now()->subDays($olderThanDays));
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }
}
