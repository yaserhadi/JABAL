<?php

namespace Modules\Tenancy\Services;

use App\Support\Contracts\Audit\AuditLoggerInterface;
use Illuminate\Support\Facades\DB;
use Modules\Billing\Models\Offering;
use Modules\Tenancy\Models\LegalOrganization;
use Modules\Tenancy\Models\LegalOrganizationBusinessOwner;
use Modules\Tenancy\Models\Tenant;

/**
 * WAVE-6 GAP-002: Legal Organization + Business Owner provisioning (central).
 * Does not grant Tenant permissions automatically.
 */
class LegalOrganizationService
{
    public function __construct(
        private readonly AuditLoggerInterface $audit
    ) {}

    public function create(string $name, ?array $metadata = null, ?string $actorId = null): LegalOrganization
    {
        $org = LegalOrganization::query()->create([
            'name' => $name,
            'status' => 'active',
            'metadata' => $metadata,
        ]);

        $this->audit->log('legal_organization.created', [
            'actor_id' => $actorId,
            'auditable_type' => LegalOrganization::class,
            'auditable_id' => $org->id,
            'new_values' => ['name' => $name, 'status' => 'active'],
        ]);

        return $org;
    }

    public function assignBusinessOwner(
        LegalOrganization $org,
        string $userId,
        ?string $primaryTenantId = null,
        ?string $assignedBy = null
    ): LegalOrganizationBusinessOwner {
        return DB::connection('central')->transaction(function () use ($org, $userId, $primaryTenantId, $assignedBy) {
            $existing = LegalOrganizationBusinessOwner::query()
                ->where('legal_organization_id', $org->id)
                ->where('user_id', $userId)
                ->first();

            if ($existing) {
                $existing->update([
                    'status' => 'active',
                    'primary_tenant_id' => $primaryTenantId ?? $existing->primary_tenant_id,
                    'assigned_at' => now(),
                    'assigned_by' => $assignedBy,
                ]);
                $owner = $existing->fresh();
            } else {
                $owner = LegalOrganizationBusinessOwner::query()->create([
                    'legal_organization_id' => $org->id,
                    'user_id' => $userId,
                    'primary_tenant_id' => $primaryTenantId,
                    'status' => 'active',
                    'assigned_at' => now(),
                    'assigned_by' => $assignedBy,
                ]);
            }

            $this->audit->log('legal_organization.business_owner_assigned', [
                'actor_id' => $assignedBy,
                'auditable_type' => LegalOrganizationBusinessOwner::class,
                'auditable_id' => $owner->id,
                'metadata' => [
                    'legal_organization_id' => $org->id,
                    'user_id' => $userId,
                    'primary_tenant_id' => $primaryTenantId,
                ],
            ]);

            return $owner;
        });
    }

    public function attachTenant(LegalOrganization $org, Tenant $tenant, ?string $offeringId = null): Tenant
    {
        $tenant->forceFill([
            'legal_organization_id' => $org->id,
            'offering_id' => $offeringId ?? $tenant->offering_id ?? $this->defaultPublishedOfferingId(),
        ])->save();

        $this->audit->log('legal_organization.tenant_attached', [
            'tenant_id' => $tenant->id,
            'auditable_type' => Tenant::class,
            'auditable_id' => $tenant->id,
            'new_values' => [
                'legal_organization_id' => $org->id,
                'offering_id' => $tenant->offering_id,
            ],
        ]);

        return $tenant->fresh();
    }

    public function defaultPublishedOfferingId(): ?string
    {
        return Offering::query()
            ->where('status', Offering::STATUS_PUBLISHED)
            ->where('code', 'jabal-standard')
            ->value('id')
            ?? Offering::query()
                ->where('status', Offering::STATUS_PUBLISHED)
                ->orderBy('code')
                ->value('id');
    }
}
