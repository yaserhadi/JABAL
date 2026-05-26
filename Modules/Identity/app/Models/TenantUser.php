<?php

namespace Modules\Identity\Models;

use App\Support\Audit\Auditable;
use App\Support\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Modules\Tenancy\Models\Tenant;
use Modules\Tenancy\Models\TenantUser as TenantMembership;
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
     * Central registry rows linking this tenant-application user to tenants.
     * pivot user_id stores tenant user uuid (not a central user).
     */
    public function tenantMemberships(): HasMany
    {
        return $this->hasMany(TenantMembership::class, 'user_id');
    }

    public function personalTenant(): ?Tenant
    {
        return Tenant::query()
            ->where('type', 'personal')
            ->where('created_by', $this->id)
            ->first();
    }

    public function homeRedirectPath(): string
    {
        $tenant = $this->personalTenant();

        if ($tenant) {
            return '/t/'.$tenant->id.'/dashboard';
        }

        return route('login');
    }

    /**
     * Resolve tenant user by email for login (shared_db: may exist in one tenant context).
     */
    public static function findForLogin(string $email): ?self
    {
        return static::withoutGlobalScope('tenant')->where('email', $email)->first();
    }

    public function scopeForTenant(Builder $query, string $tenantId): Builder
    {
        return $query->where($query->getModel()->getTable().'.tenant_id', $tenantId);
    }
}
