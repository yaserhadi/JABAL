<?php

namespace Modules\Tenancy\Services;

use App\Support\Contracts\Audit\AuditLoggerInterface;
use App\Support\Contracts\Billing\TenantEntitlementsResolver;
use App\Support\Contracts\Tenancy\TenantSetupReadinessEvaluator;
use Illuminate\Support\Facades\DB;
use Modules\Tenancy\Models\SetupDefinition;
use Modules\Tenancy\Models\Tenant;
use Modules\Tenancy\Models\TenantSetupState;

/**
 * WAVE-6 GAP-003: Operational Readiness from applicable Blocking setup definitions.
 * setup_grandfathered tenants are Ready (existing-tenant safety).
 */
class TenantSetupReadinessService implements TenantSetupReadinessEvaluator
{
    public function __construct(
        private readonly TenantEntitlementsResolver $entitlements,
        private readonly AuditLoggerInterface $audit
    ) {}

    public function isOperationallyReady(string $tenantId): bool
    {
        return $this->evaluate($tenantId)['ready'];
    }

    public function evaluate(string $tenantId): array
    {
        $tenant = Tenant::query()->find($tenantId);
        if ($tenant === null) {
            return [
                'ready' => false,
                'blocking_incomplete' => ['tenant_missing'],
                'optional_incomplete' => [],
                'applicable' => [],
            ];
        }

        if ($tenant->setup_grandfathered) {
            return [
                'ready' => true,
                'blocking_incomplete' => [],
                'optional_incomplete' => [],
                'applicable' => [],
                'grandfathered' => true,
            ];
        }

        $definitions = SetupDefinition::query()
            ->where('is_active', true)
            ->orderBy('code')
            ->orderByDesc('version')
            ->get()
            ->unique('code')
            ->values();

        $states = TenantSetupState::query()
            ->where('tenant_id', $tenantId)
            ->get()
            ->keyBy('setup_definition_id');

        $blockingIncomplete = [];
        $optionalIncomplete = [];
        $applicable = [];

        foreach ($definitions as $definition) {
            if (! $this->isApplicable($tenantId, $definition)) {
                continue;
            }

            $state = $states->get($definition->id);
            $status = $state?->status ?? TenantSetupState::STATUS_PENDING;
            $row = [
                'code' => $definition->code,
                'version' => $definition->version,
                'requirement_type' => $definition->requirement_type,
                'status' => $status,
                'title' => $definition->title,
            ];
            $applicable[] = $row;

            $complete = in_array($status, [
                TenantSetupState::STATUS_COMPLETED,
                TenantSetupState::STATUS_NOT_APPLICABLE,
            ], true);

            if ($complete) {
                continue;
            }

            if ($definition->requirement_type === SetupDefinition::TYPE_OPTIONAL) {
                $optionalIncomplete[] = $definition->code;
            } else {
                // blocking + conditional (when applicable) block readiness
                $blockingIncomplete[] = $definition->code;
            }
        }

        return [
            'ready' => $blockingIncomplete === [],
            'blocking_incomplete' => $blockingIncomplete,
            'optional_incomplete' => $optionalIncomplete,
            'applicable' => $applicable,
        ];
    }

    public function isApplicable(string $tenantId, SetupDefinition $definition): bool
    {
        // Conditional: only when condition entitlement is present.
        if ($definition->requirement_type === SetupDefinition::TYPE_CONDITIONAL) {
            $code = $definition->condition_entitlement_code;
            if ($code === null || $code === '') {
                return false;
            }

            return $this->entitlements->tenantHasEntitlement($tenantId, $code);
        }

        // Blocking/optional tied to entitlement/capability: applicable only when tenant has it.
        $gate = $definition->condition_entitlement_code ?? $definition->capability_code;
        if ($gate !== null && $gate !== '') {
            return $this->entitlements->tenantHasEntitlement($tenantId, $gate);
        }

        return true;
    }

    public function complete(
        string $tenantId,
        string $definitionCode,
        ?string $completedBy = null,
        ?array $evidence = null
    ): TenantSetupState {
        return DB::connection('central')->transaction(function () use ($tenantId, $definitionCode, $completedBy, $evidence) {
            $definition = SetupDefinition::query()
                ->where('code', $definitionCode)
                ->where('is_active', true)
                ->orderByDesc('version')
                ->firstOrFail();

            $before = $this->evaluate($tenantId);

            $state = TenantSetupState::query()->updateOrCreate(
                [
                    'tenant_id' => $tenantId,
                    'setup_definition_id' => $definition->id,
                ],
                [
                    'definition_version' => $definition->version,
                    'status' => TenantSetupState::STATUS_COMPLETED,
                    'completed_at' => now(),
                    'completed_by' => $completedBy,
                    'evidence' => $evidence,
                ]
            );

            $after = $this->evaluate($tenantId);

            $this->audit->log('tenant.setup.completed', [
                'tenant_id' => $tenantId,
                'actor_id' => $completedBy,
                'auditable_type' => TenantSetupState::class,
                'auditable_id' => $state->id,
                'metadata' => [
                    'setup_code' => $definitionCode,
                    'definition_version' => $definition->version,
                    'ready_before' => $before['ready'],
                    'ready_after' => $after['ready'],
                ],
            ]);

            if ($before['ready'] !== $after['ready']) {
                $this->audit->log('tenant.setup.readiness_transition', [
                    'tenant_id' => $tenantId,
                    'actor_id' => $completedBy,
                    'auditable_type' => Tenant::class,
                    'auditable_id' => $tenantId,
                    'metadata' => ['ready' => $after['ready']],
                ]);
            }

            return $state;
        });
    }

    /**
     * Initialize setup states for a tenant without marking complete (new tenants).
     */
    public function initializeForTenant(string $tenantId): void
    {
        $definitions = SetupDefinition::query()->where('is_active', true)->get();

        foreach ($definitions as $definition) {
            TenantSetupState::query()->firstOrCreate(
                [
                    'tenant_id' => $tenantId,
                    'setup_definition_id' => $definition->id,
                ],
                [
                    'definition_version' => $definition->version,
                    'status' => TenantSetupState::STATUS_PENDING,
                ]
            );
        }
    }
}
