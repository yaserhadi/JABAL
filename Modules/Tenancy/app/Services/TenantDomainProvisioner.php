<?php

declare(strict_types=1);

namespace Modules\Tenancy\Services;

use App\Models\Domain;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Modules\Tenancy\Exceptions\DomainCollisionException;
use Modules\Tenancy\Models\Tenant;
use Modules\Tenancy\Support\TenantHandleValidator;

/**
 * Collision-safe platform-subdomain domain row provisioner (BK-073).
 *
 * Algorithm: normalize → lookup → atomic create; same-Tenant idempotent;
 * other-Tenant HARD FAIL; unique-race re-read; never auto-rebind.
 */
class TenantDomainProvisioner
{
    public function __construct(
        private readonly TenantHandleValidator $handles,
    ) {}

    public function ensurePlatformSubdomain(Tenant $tenant): Domain
    {
        $raw = (string) ($tenant->slug ?? '');
        if ($raw === '') {
            throw new DomainCollisionException(
                domainLabel: '',
                message: 'Tenant has no Handle (slug); cannot reserve platform subdomain.',
            );
        }

        $normalized = $this->handles->normalize($raw);
        if ($normalized !== $raw) {
            throw new DomainCollisionException(
                domainLabel: $normalized,
                message: "Handle [{$raw}] is not normalized; provisioner does not silently correct. Expected [{$normalized}].",
            );
        }

        if (str_contains($normalized, '.')) {
            throw new DomainCollisionException(
                domainLabel: $normalized,
                message: 'Platform subdomain must be a single DNS label (no dots).',
            );
        }

        $category = (string) config('tenancy_addressing.domain_category_platform_subdomain', 'platform_subdomain');

        return DB::connection('central')->transaction(function () use ($tenant, $normalized, $category) {
            $existing = Domain::query()->where('domain', $normalized)->first();

            if ($existing instanceof Domain) {
                return $this->assertSameTenantOrFail($existing, $tenant, $normalized);
            }

            try {
                /** @var Domain $created */
                $created = $tenant->domains()->create([
                    'domain' => $normalized,
                    'data' => ['category' => $category],
                ]);

                return $created;
            } catch (QueryException $e) {
                if (! $this->isUniqueViolation($e)) {
                    throw $e;
                }

                $raced = Domain::query()->where('domain', $normalized)->first();
                if (! $raced instanceof Domain) {
                    throw $e;
                }

                return $this->assertSameTenantOrFail($raced, $tenant, $normalized);
            }
        });
    }

    private function assertSameTenantOrFail(Domain $existing, Tenant $tenant, string $label): Domain
    {
        if ((string) $existing->tenant_id === (string) $tenant->id) {
            $category = (string) config('tenancy_addressing.domain_category_platform_subdomain', 'platform_subdomain');
            $data = is_array($existing->data) ? $existing->data : [];
            if (($data['category'] ?? null) !== $category) {
                $data['category'] = $category;
                $existing->data = $data;
                $existing->save();
            }

            return $existing;
        }

        throw new DomainCollisionException(
            domainLabel: $label,
            existingTenantId: (string) $existing->tenant_id,
        );
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        $sqlState = (string) ($e->errorInfo[0] ?? '');
        $driverCode = (int) ($e->errorInfo[1] ?? 0);
        $message = strtolower($e->getMessage());

        return $sqlState === '23505'
            || $driverCode === 1062
            || str_contains($message, 'unique')
            || str_contains($message, 'duplicate');
    }
}
