<?php

namespace Modules\Tenancy\Services;

use App\Support\Contracts\Audit\AuditLoggerInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;
use Modules\Tenancy\Data\TenantOnboardingInput;
use Modules\Tenancy\Data\TenantProvisioningResult;
use Modules\Tenancy\Models\Tenant;
use Modules\Tenancy\Models\TenantContact;
use Modules\Tenancy\Support\TenantHandleValidator;
use Modules\Tenancy\Support\TenantProvisioningPresenter;

/**
 * Platform Tenant Registry — central list/detail + create/update (BK-069).
 */
final class PlatformTenantRegistryService
{
    public function __construct(
        private readonly TenantOnboardingService $onboarding,
        private readonly TenantHandleValidator $handles,
        private readonly TenantProvisioningPresenter $presenter,
        private readonly PlatformTenantApplicationOwnerResolver $applicationOwners,
        private readonly AuditLoggerInterface $audit,
    ) {}

    /**
     * Central-only list. No Application Owner. No tenant fan-out.
     *
     * @param  array{search?: string, status?: string, isolation_level?: string, provisioning_status?: string, per_page?: int}  $filters
     */
    public function list(array $filters = []): LengthAwarePaginator
    {
        $query = Tenant::query()
            ->with('databaseConfig')
            ->orderByDesc('created_at');

        if (! empty($filters['search'])) {
            $term = '%'.str_replace(['%', '_'], ['\\%', '\\_'], (string) $filters['search']).'%';
            $query->where(function (Builder $q) use ($term): void {
                $q->where('name', 'like', $term)
                    ->orWhere('slug', 'like', $term);
            });
        }

        if (! empty($filters['status'])) {
            $query->where('status', (string) $filters['status']);
        }

        if (! empty($filters['isolation_level'])) {
            $query->where('isolation_level', (string) $filters['isolation_level']);
        }

        if (! empty($filters['provisioning_status']) && $this->presenter->supportsCentralListFilter()) {
            $this->applyCentralProvisioningFilter($query, (string) $filters['provisioning_status']);
        }

        $perPage = max(1, min(100, (int) ($filters['per_page'] ?? 15)));

        return $query->paginate($perPage)->through(fn (Tenant $tenant) => $this->toListItem($tenant));
    }

    /**
     * @return array<string, mixed>
     */
    public function detail(Tenant $tenant): array
    {
        $tenant->loadMissing('databaseConfig');
        $presentation = $this->presenter->fromTenant($tenant);
        $applicationOwner = $this->applicationOwners->resolve($tenant);
        $commercialOwner = $this->resolveCommercialOwnerContact($tenant);

        return [
            'id' => $tenant->id,
            'name' => $tenant->name,
            'handle' => $tenant->slug,
            'entry_url' => app(\App\Http\Auth\TenantEntryUrlResolver::class)->entryUrl($tenant),
            'isolation_level' => $tenant->isolation_level,
            'lifecycle_status' => $presentation['lifecycle_status'],
            'provisioning_status' => $presentation['status'],
            'provisioning_detail' => $presentation['detail'],
            'establishment_complete' => (bool) ($presentation['establishment_complete'] ?? false),
            'establishment_detail' => (string) ($presentation['establishment_detail'] ?? ''),
            'establishment' => $presentation['establishment'] ?? null,
            'application_owner' => $applicationOwner,
            'commercial_owner_contact' => $commercialOwner,
            'created_at' => optional($tenant->created_at)?->toIso8601String(),
        ];
    }

    /**
     * @param  array{organization_name: string, handle: string, owner_name: string, owner_email: string, owner_password: string}  $data
     * @return array{http_status: int, payload: array<string, mixed>}
     */
    public function create(array $data, ?string $actorId = null): array
    {
        $handle = $this->handles->assertValidForCreate((string) $data['handle']);

        $isolation = (string) config('tenancy_storage.default_isolation_level', 'shared');
        if (! in_array($isolation, ['shared', 'database'], true)) {
            $isolation = 'shared';
        }

        $input = new TenantOnboardingInput(
            organizationName: (string) $data['organization_name'],
            ownerName: (string) $data['owner_name'],
            ownerEmail: (string) $data['owner_email'],
            ownerPassword: (string) $data['owner_password'],
            isolationLevel: $isolation,
            slug: $handle,
        );

        try {
            $result = $this->onboarding->onboardOrganizationTenant($input);
        } catch (\Illuminate\Database\QueryException $e) {
            if ($this->isUniqueViolation($e)) {
                throw ValidationException::withMessages([
                    'handle' => ['This Tenant Handle is not available.'],
                ]);
            }
            throw $e;
        }

        $presentation = $this->presenter->fromProvisioningResult($result);
        $complete = $this->onboarding->isProvisioningComplete($result);

        $this->audit->log('platform_tenant.created', [
            'actor_id' => $actorId,
            'auditable_type' => Tenant::class,
            'auditable_id' => $result->tenant->id,
            'new_values' => [
                'name' => $result->tenant->name,
                'handle' => $result->tenant->slug,
                'isolation_level' => $result->tenant->isolation_level,
                'provisioning_status' => $presentation['status'],
            ],
            'metadata' => [
                'handle_allocated' => $result->tenant->slug,
                'http_outcome' => $complete ? 201 : 202,
            ],
        ]);

        if (! $complete) {
            $this->audit->log('platform_tenant.provisioning_incomplete', [
                'actor_id' => $actorId,
                'auditable_type' => Tenant::class,
                'auditable_id' => $result->tenant->id,
                'metadata' => $presentation,
            ]);
        }

        return [
            'http_status' => $complete ? 201 : 202,
            'payload' => $this->toCreatePayload($result, $presentation),
        ];
    }

    /**
     * Safe profile update: display name only.
     *
     * @return array<string, mixed>
     */
    public function updateName(Tenant $tenant, string $name, ?string $actorId = null): array
    {
        $before = ['name' => $tenant->name];
        $tenant->update(['name' => $name]);

        $this->audit->log('platform_tenant.updated', [
            'actor_id' => $actorId,
            'auditable_type' => Tenant::class,
            'auditable_id' => $tenant->id,
            'old_values' => $before,
            'new_values' => ['name' => $tenant->name],
        ]);

        return $this->detail($tenant->fresh(['databaseConfig']));
    }

    /**
     * @return array{code: string, message: string, handle: string}
     */
    public function checkHandleAvailability(string $rawHandle): array
    {
        $result = $this->handles->evaluate($rawHandle, checkAvailability: true);

        // Non-PII: do not attach tenant name/id
        return [
            'code' => $result['code'],
            'message' => $result['message'],
            'handle' => $result['handle'],
        ];
    }

    /**
     * @return array{id: string, name: string, handle: string, entry_url: string, isolation_level: string, lifecycle_status: string, provisioning_status: string, provisioning_detail: string, created_at: ?string}
     */
    private function toListItem(Tenant $tenant): array
    {
        $presentation = $this->presenter->fromTenant($tenant);

        return [
            'id' => $tenant->id,
            'name' => $tenant->name,
            'handle' => $tenant->slug,
            'entry_url' => app(\App\Http\Auth\TenantEntryUrlResolver::class)->entryUrl($tenant),
            'isolation_level' => $tenant->isolation_level,
            'lifecycle_status' => $presentation['lifecycle_status'],
            'provisioning_status' => $presentation['status'],
            'provisioning_detail' => $presentation['detail'],
            'establishment_complete' => (bool) ($presentation['establishment_complete'] ?? false),
            'created_at' => optional($tenant->created_at)?->toIso8601String(),
        ];
    }

    /**
     * @param  array{status: string, detail: string, lifecycle_status: string, ready_flags?: array}  $presentation
     * @return array<string, mixed>
     */
    private function toCreatePayload(TenantProvisioningResult $result, array $presentation): array
    {
        $tenant = $result->tenant;

        return [
            'id' => $tenant->id,
            'name' => $tenant->name,
            'handle' => $tenant->slug,
            'entry_url' => app(\App\Http\Auth\TenantEntryUrlResolver::class)->entryUrl($tenant),
            'isolation_level' => $tenant->isolation_level,
            'lifecycle_status' => $presentation['lifecycle_status'],
            'provisioning_status' => $presentation['status'],
            'provisioning_detail' => $presentation['detail'],
            'ready_flags' => $presentation['ready_flags'] ?? null,
            'establishment_complete' => (bool) ($presentation['establishment_complete'] ?? false),
            'establishment_detail' => (string) ($presentation['establishment_detail'] ?? ''),
            'establishment' => $presentation['establishment'] ?? null,
            'application_owner' => $result->owner ? [
                'id' => (string) $result->owner->id,
                'name' => (string) $result->owner->name,
                'email' => (string) $result->owner->email,
            ] : null,
        ];
    }

    /**
     * @return array{id: string, full_name: string, email: ?string}|null|array{assigned: false}
     */
    private function resolveCommercialOwnerContact(Tenant $tenant): array
    {
        $contactId = $tenant->getAttribute('commercial_owner_contact_id');
        if (! $contactId) {
            return ['assigned' => false];
        }

        $contact = TenantContact::query()->find($contactId);
        if ($contact === null) {
            return ['assigned' => false];
        }

        return [
            'assigned' => true,
            'id' => (string) $contact->id,
            'full_name' => (string) $contact->full_name,
            'email' => $contact->email ? (string) $contact->email : null,
        ];
    }

    private function applyCentralProvisioningFilter(Builder $query, string $status): void
    {
        if ($status === TenantProvisioningPresenter::ACTION_REQUIRED) {
            $query->where(function (Builder $q): void {
                $q->where('isolation_level', 'database')
                    ->where(function (Builder $inner): void {
                        $inner->whereDoesntHave('databaseConfig')
                            ->orWhereHas('databaseConfig', function (Builder $cfg): void {
                                $cfg->where('provisioning_status', '!=', 'active')
                                    ->orWhereNull('database_name');
                            });
                    });
            });

            return;
        }

        if ($status === TenantProvisioningPresenter::COMPLETED) {
            $query->where(function (Builder $q): void {
                $q->where('isolation_level', '!=', 'database')
                    ->orWhereHas('databaseConfig', function (Builder $cfg): void {
                        $cfg->where('provisioning_status', 'active')
                            ->whereNotNull('database_name');
                    });
            });
        }
    }

    private function isUniqueViolation(\Illuminate\Database\QueryException $e): bool
    {
        $code = (string) ($e->errorInfo[0] ?? '');
        $driverCode = (string) ($e->errorInfo[1] ?? '');

        return $code === '23000' || $driverCode === '1062' || str_contains(strtolower($e->getMessage()), 'unique');
    }
}
