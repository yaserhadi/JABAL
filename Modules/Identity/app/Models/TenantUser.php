<?php

namespace Modules\Identity\Models;

use App\Support\Audit\Auditable;
use App\Support\Tenancy\TenantAuthLookup;
use App\Support\Traits\BelongsToTenant;
use App\Support\Traits\ResolvesTenantStorageConnection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Modules\Tenancy\Models\Tenant;
use Spatie\Permission\Traits\HasRoles;

/**
 * Tenant Application user (ADR-0007). Lives on tenant data layer — not platform_users.
 */
class TenantUser extends Authenticatable
{
    use Auditable;
    use BelongsToTenant;
    use HasApiTokens;
    use HasFactory;
    use ResolvesTenantStorageConnection;

    protected static function newFactory(): \Database\Factories\UserFactory
    {
        return \Database\Factories\UserFactory::new();
    }
    use HasRoles;
    use HasUuids;
    use Notifiable;
    use SoftDeletes;

    protected $connection = 'tenant';

    protected $table = 'users';

    protected string $guard_name = 'web';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'tenant_id',
        'name',
        'email',
        'password',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * Tenant-layer membership records (auth authority — ADR-0007 R11).
     */
    public function tenantMemberships(): HasMany
    {
        return $this->hasMany(Membership::class, 'user_id');
    }

    /**
     * Resolve home Tenant via active Membership (owner preferred).
     * BK-064: created_by is provenance only — not ownership/redirect SSOT.
     */
    public function homeTenant(): ?Tenant
    {
        if (tenancy()->initialized && tenancy()->tenant) {
            $current = tenancy()->tenant;
            $inCurrent = Membership::query()
                ->where('user_id', $this->id)
                ->where('tenant_id', $current->id)
                ->where('status', 'active')
                ->exists();

            if ($inCurrent) {
                return $current;
            }
        }

        $membership = Membership::query()
            ->withoutGlobalScope('tenant')
            ->where('user_id', $this->id)
            ->where('status', 'active')
            ->orderByRaw("CASE WHEN membership_type = 'owner' THEN 0 ELSE 1 END")
            ->orderBy('joined_at')
            ->first();

        if ($membership) {
            return Tenant::query()->find($membership->tenant_id);
        }

        // Registry pointer on the user row (shared_db or dedicated). Ownership remains Membership-only;
        // callers that require membership must assert separately (EnsureUserBelongsToTenant).
        if ($this->tenant_id) {
            return Tenant::query()->find($this->tenant_id);
        }

        return null;
    }

    /**
     * @deprecated BK-064 — use homeTenant(); kept as thin alias during test migration.
     */
    public function personalTenant(): ?Tenant
    {
        return $this->homeTenant();
    }

    public function homeRedirectPath(): string
    {
        $tenant = $this->homeTenant();

        if ($tenant) {
            return '/t/'.$tenant->entryKey().'/dashboard';
        }

        return route('login');
    }

    /**
     * Resolve tenant user by email for login (shared_db: may exist in one tenant context).
     */
    public static function findForLogin(string $email): ?self
    {
        return app(TenantAuthLookup::class)->findUserByEmail($email);
    }

    public function scopeForTenant(Builder $query, string $tenantId): Builder
    {
        return $query->where($query->getModel()->getTable().'.tenant_id', $tenantId);
    }
}
