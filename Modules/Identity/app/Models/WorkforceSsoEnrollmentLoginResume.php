<?php

namespace Modules\Identity\Models;

use App\Support\Traits\BelongsToTenant;
use App\Support\Traits\ResolvesTenantStorageConnection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * BK-099: Opaque short-lived login resume after invitation open → Tenant login.
 */
class WorkforceSsoEnrollmentLoginResume extends Model
{
    use BelongsToTenant;
    use HasUuids;
    use ResolvesTenantStorageConnection;

    protected $connection = 'tenant';

    protected $table = 'workforce_sso_enrollment_login_resumes';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'invitation_id',
        'tenant_id',
        'tenant_host',
        'token_hash',
        'browser_binding_secret_hash',
        'expires_at',
        'consumed_at',
    ];

    protected $hidden = [
        'token_hash',
        'browser_binding_secret_hash',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'consumed_at' => 'datetime',
    ];

    public function invitation(): BelongsTo
    {
        return $this->belongsTo(WorkforceSsoEnrollmentInvitation::class, 'invitation_id');
    }

    public function isUsable(): bool
    {
        return $this->consumed_at === null
            && $this->expires_at !== null
            && $this->expires_at->isFuture();
    }
}
