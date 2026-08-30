<?php

namespace Modules\Billing\Services;

use App\Support\Contracts\Audit\AuditLoggerInterface;
use Illuminate\Support\Facades\DB;
use Modules\Billing\Models\Capability;
use Modules\Billing\Models\Offering;
use Modules\Billing\Models\Plan;
use Modules\Billing\Models\Product;
use Modules\Tenancy\Models\Tenant;

/**
 * WAVE-6 GAP-001 catalog bootstrap + offering assignment (no payment).
 * BK-115 PR-04: publish transitions go through OfferingPublishGate (HARD BLOCK).
 */
class ProductCatalogService
{
    public function __construct(
        private readonly AuditLoggerInterface $audit,
        private readonly OfferingPublishGate $publishGate,
    ) {}

    public function ensureDefaultCatalog(): Offering
    {
        return DB::connection('central')->transaction(function () {
            $product = Product::query()->firstOrCreate(
                ['code' => 'jabal'],
                [
                    'name' => 'Jabal',
                    'description' => 'Jabal multi-tenant platform product',
                    'is_active' => true,
                ]
            );

            $capabilities = [
                ['code' => 'user_management', 'name' => 'User Management', 'entitlement_code' => null],
                ['code' => 'mfa', 'name' => 'Multi-Factor Authentication', 'entitlement_code' => 'mfa_available'],
                ['code' => 'sso', 'name' => 'Single Sign-On', 'entitlement_code' => 'sso_available'],
            ];

            $capabilityModels = [];
            foreach ($capabilities as $row) {
                $capabilityModels[$row['code']] = Capability::query()->firstOrCreate(
                    ['code' => $row['code']],
                    [
                        'name' => $row['name'],
                        'entitlement_code' => $row['entitlement_code'],
                        'is_active' => true,
                    ]
                );
                $product->capabilities()->syncWithoutDetaching([$capabilityModels[$row['code']]->id]);
            }

            $plan = Plan::query()->where('code', Plan::DEFAULT_CODE)->first();

            $offering = Offering::query()->firstOrCreate(
                ['code' => 'jabal-standard'],
                [
                    'name' => 'Jabal Standard',
                    'product_id' => $product->id,
                    'plan_id' => $plan?->id,
                    'status' => Offering::STATUS_DRAFT,
                    'version' => 1,
                    'metadata' => ['source' => 'wave6'],
                ]
            );

            // Ensure commercial identity fields present for republish paths.
            $offering->fill([
                'name' => $offering->name ?: 'Jabal Standard',
                'product_id' => $offering->product_id ?: $product->id,
                'plan_id' => $offering->plan_id ?: $plan?->id,
            ]);
            if ($offering->isDirty()) {
                $offering->save();
            }

            $sync = [];
            foreach ($capabilityModels as $cap) {
                $sync[$cap->id] = ['included' => true];
            }
            $offering->capabilities()->sync($sync);
            $offering->refresh();

            if ($offering->status !== Offering::STATUS_PUBLISHED) {
                $this->publish($offering);
            } else {
                // Already published: re-assert gate so invalid drift cannot remain silent.
                $this->publishGate->assertMayPublish($offering->fresh(['product', 'plan.entitlements', 'capabilities']));
            }

            return $offering->fresh(['product', 'capabilities', 'plan']);
        });
    }

    /**
     * Authoritative publish transition — HARD BLOCK via OfferingPublishGate.
     */
    public function publish(Offering $offering): Offering
    {
        $offering->loadMissing(['product', 'plan.entitlements', 'capabilities']);
        $this->publishGate->assertMayPublish($offering);

        if ($offering->status === Offering::STATUS_PUBLISHED) {
            return $offering;
        }

        $before = $offering->status;
        $offering->status = Offering::STATUS_PUBLISHED;
        $offering->save();

        $this->audit->log('offering.published', [
            'auditable_type' => Offering::class,
            'auditable_id' => $offering->id,
            'old_values' => ['status' => $before],
            'new_values' => ['status' => Offering::STATUS_PUBLISHED],
        ]);

        return $offering->fresh(['product', 'capabilities', 'plan']);
    }

    public function assignOfferingToTenant(Tenant $tenant, Offering $offering, ?string $actorId = null): Tenant
    {
        if (! $offering->isPublished()) {
            throw new \InvalidArgumentException('Only published offerings may be assigned.');
        }

        $before = $tenant->offering_id;
        $tenant->forceFill(['offering_id' => $offering->id])->save();

        $this->audit->log('tenant.offering_assigned', [
            'tenant_id' => $tenant->id,
            'actor_id' => $actorId,
            'auditable_type' => Tenant::class,
            'auditable_id' => $tenant->id,
            'old_values' => ['offering_id' => $before],
            'new_values' => ['offering_id' => $offering->id],
        ]);

        return $tenant->fresh();
    }
}
