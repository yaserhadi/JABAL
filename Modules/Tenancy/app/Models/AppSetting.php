<?php

namespace Modules\Tenancy\Models;

use App\Support\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * BK-028 / DEC-0011: Tenant-owned operational settings (tenant data layer).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string|null $display_name
 * @property string|null $timezone
 * @property string|null $locale
 * @property string|null $branding_logo_url
 * @property string|null $member_removal_mode
 */
class AppSetting extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $connection = 'tenant';

    protected $table = 'app_settings';

    protected $fillable = [
        'tenant_id',
        'display_name',
        'timezone',
        'locale',
        'branding_logo_url',
        'member_removal_mode',
    ];
}
