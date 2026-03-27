<?php

namespace Modules\Tenancy\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase 3D: Central tenant configuration (branding, locale, timezone).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string|null $display_name
 * @property string|null $timezone
 * @property string|null $locale
 * @property string|null $branding_logo_url
 */
class TenantSetting extends Model
{
    use HasUuids;

    protected $connection = 'central';

    protected $table = 'tenant_settings';

    protected $fillable = [
        'tenant_id',
        'display_name',
        'timezone',
        'locale',
        'branding_logo_url',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }
}
