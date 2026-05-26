<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * Platform Management operator — central DB only (ADR-0007).
 */
class PlatformUser extends Authenticatable
{
    use HasUuids;
    use Notifiable;
    use SoftDeletes;

    protected $connection = 'central';

    protected $table = 'platform_users';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'name',
        'email',
        'password',
        'is_active',
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
            'is_active' => 'boolean',
        ];
    }

    public function isPlatformOperator(): bool
    {
        return (bool) $this->is_active;
    }

    public function homeRedirectPath(): string
    {
        return route('platform.settings.index');
    }
}
