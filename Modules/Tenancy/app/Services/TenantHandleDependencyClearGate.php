<?php

declare(strict_types=1);

namespace Modules\Tenancy\Services;

use App\Models\Domain;
use App\Support\Tenancy\TenantAddressingProfile;
use Modules\Identity\Models\SsoAuthenticationTransaction;
use Modules\Identity\Models\WorkforceSsoEnrollmentInvitation;
use Modules\Tenancy\Models\Tenant;
use Modules\Tenancy\Support\TenantHandleValidator;

/**
 * BK-125 Wave 7 — dependency-clear gate before handle → AVAILABLE.
 *
 * Time alone never releases. Each check returns one of:
 * cleared | expired | invalidated | Tenant-ID-safe | release-blocking
 */
final class TenantHandleDependencyClearGate
{
    public function __construct(
        private readonly TenantHandleValidator $handles,
        private readonly TenantAddressingProfile $addressing,
    ) {}

    /**
     * @return array<string, string>
     */
    public function evaluate(string $handle, ?string $formerTenantId): array
    {
        $normalized = $this->handles->normalize($handle);
        $fqdn = strtolower($this->addressing->tenantHostFqdn($normalized));

        return [
            'domain_mapping' => $this->domainMapping($normalized),
            'open_sso_transactions' => $this->openSsoTransactions($normalized, $fqdn, $formerTenantId),
            'pending_workforce_enrollment' => $this->pendingWorkforceEnrollment($fqdn, $formerTenantId),
            'active_sessions' => 'Tenant-ID-safe',
            'remember_me' => 'Tenant-ID-safe',
            'pending_invitations' => 'Tenant-ID-safe',
            'password_reset_tokens' => 'Tenant-ID-safe',
            'email_verification' => 'Tenant-ID-safe',
            'signed_urls' => 'Tenant-ID-safe',
            'queued_jobs' => 'Tenant-ID-safe',
            'cached_redirects' => 'cleared',
            'stored_aliases' => 'cleared',
            'external_dns' => 'cleared',
        ];
    }

    private function domainMapping(string $normalized): string
    {
        $exists = Domain::query()->where('domain', $normalized)->exists();

        return $exists ? 'release-blocking' : 'cleared';
    }

    private function openSsoTransactions(string $normalized, string $fqdn, ?string $formerTenantId): string
    {
        $query = SsoAuthenticationTransaction::query()
            ->whereNull('consumed_at')
            ->whereNotIn('status', [
                SsoAuthenticationTransaction::STATUS_FAILED,
                SsoAuthenticationTransaction::STATUS_EXPIRED,
                SsoAuthenticationTransaction::STATUS_CONSUMED,
                SsoAuthenticationTransaction::STATUS_SUPERSEDED,
            ])
            ->where(function ($q) use ($normalized, $fqdn): void {
                $q->where('destination_host', $fqdn)
                    ->orWhere('destination_host', $normalized)
                    ->orWhere('destination_host', 'like', $normalized.'.%');
            });

        if ($formerTenantId) {
            $query->where('tenant_id', $formerTenantId);
        }

        return $query->exists() ? 'release-blocking' : 'cleared';
    }

    private function pendingWorkforceEnrollment(string $fqdn, ?string $formerTenantId): string
    {
        if (! $formerTenantId) {
            return 'cleared';
        }

        $tenant = Tenant::query()->find($formerTenantId);
        if (! $tenant) {
            return 'cleared';
        }

        try {
            tenancy()->initialize($tenant);

            $pending = WorkforceSsoEnrollmentInvitation::query()
                ->where('tenant_id', $formerTenantId)
                ->where('tenant_host', $fqdn)
                ->whereNull('consumed_at')
                ->whereNull('cancelled_at')
                ->where(function ($q): void {
                    $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
                })
                ->exists();

            return $pending ? 'release-blocking' : 'cleared';
        } catch (\Throwable) {
            // Cannot prove clear — fail closed.
            return 'release-blocking';
        } finally {
            if (tenancy()->initialized) {
                tenancy()->end();
            }
        }
    }

    /**
     * True when no release-blocking dependency remains.
     */
    public function isClear(string $handle, ?string $formerTenantId): bool
    {
        foreach ($this->evaluate($handle, $formerTenantId) as $status) {
            if ($status === 'release-blocking') {
                return false;
            }
        }

        return true;
    }
}
