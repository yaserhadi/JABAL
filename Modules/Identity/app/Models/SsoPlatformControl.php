<?php

namespace Modules\Identity\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * BK-082 WS8: singleton-style platform SSO operational controls (central).
 */
class SsoPlatformControl extends Model
{
    use HasUuids;

    protected $connection = 'central';

    protected $table = 'sso_platform_controls';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'pause_new_initiations',
        'disable_enterprise_sso',
    ];

    protected $casts = [
        'pause_new_initiations' => 'boolean',
        'disable_enterprise_sso' => 'boolean',
    ];

    public static function current(): self
    {
        $row = static::query()->orderBy('created_at')->first();
        if ($row instanceof self) {
            return $row;
        }

        return static::query()->create([
            'pause_new_initiations' => false,
            'disable_enterprise_sso' => false,
        ]);
    }
}
