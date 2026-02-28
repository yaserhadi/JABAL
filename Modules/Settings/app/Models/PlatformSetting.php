<?php

namespace Modules\Settings\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class PlatformSetting extends Model
{
    use HasUuids;

    protected $connection = 'central';
    protected $table = 'platform_settings';

    protected $fillable = [
        'group',
        'key',
        'value',
        'is_encrypted',
        'updated_by',
    ];

    protected $casts = [
        'value' => 'array',
        'is_encrypted' => 'boolean',
    ];
}
