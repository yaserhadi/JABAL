<?php

namespace Modules\Billing\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * WAVE-6: Product Capability ≠ Spatie Permission.
 * Capability = Tenant functional availability; may map to Billing entitlement_code.
 */
class Capability extends Model
{
    use HasUuids;

    protected $connection = 'central';

    protected $fillable = ['code', 'name', 'description', 'entitlement_code', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'product_capabilities');
    }

    public function offerings(): BelongsToMany
    {
        return $this->belongsToMany(Offering::class, 'offering_capabilities')
            ->withPivot('included');
    }
}
