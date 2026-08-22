<?php

namespace Modules\Identity\Services;

use App\Support\Contracts\Audit\AuditLoggerInterface;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Modules\Tenancy\Models\Tenant;

/**
 * WAVE-5 Enforcement Readiness Gate — fail closed before SSO-only activation.
 */
class SsoEnforcementReadinessGate
{
    public function __construct(
        protected SsoReadinessAccountingService $accounting,
        protected LastUsablePrivilegedAdminGuard $lastAdmin,
        protected SsoConfigService $configService,
        protected AuditLoggerInterface $audit,
    ) {}

    /**
     * @return array{pass: bool, failures: list<string>, counts: array<string, int>}
     */
    public function evaluate(Tenant $tenant): array
    {
        $wasInitialized = tenancy()->initialized;
        if (! $wasInitialized) {
            tenancy()->initialize($tenant);
        }

        try {
            $failures = [];

            $activeVersionId = $this->configService->getActiveVersionId($tenant);
            if (! is_string($activeVersionId) || $activeVersionId === '') {
                $failures[] = 'no_active_sso_connection_version';
            }

            if (! $this->configService->isOperationalForTenant($tenant)) {
                $failures[] = 'sso_connection_not_operational';
            }

            $counts = $this->accounting->counts($tenant);
            if ($counts['not_ready'] > 0) {
                $failures[] = 'population_not_ready:'.$counts['not_ready'];
            }

            if (! $this->lastAdmin->hasUsablePrivilegedAdminUnderSsoOnly($tenant)) {
                $failures[] = 'last_usable_privileged_admin_unsafe';
            }

            if (! Schema::connection('tenant')->hasTable('temporary_password_recoveries')
                || ! Schema::connection('central')->hasTable('platform_emergency_authority_cases')
            ) {
                $failures[] = 'emergency_recovery_capability_missing';
            }

            $pass = $failures === [];

            $this->audit->log($pass ? 'sso_enforcement.readiness_gate.pass' : 'sso_enforcement.readiness_gate.fail', [
                'tenant_id' => (string) $tenant->id,
                'new_values' => [
                    'failures' => $failures,
                    'counts' => $counts,
                ],
            ]);

            return [
                'pass' => $pass,
                'failures' => $failures,
                'counts' => $counts,
            ];
        } finally {
            if (! $wasInitialized) {
                tenancy()->end();
            }
        }
    }

    public function assertMayActivateSsoOnly(Tenant $tenant): void
    {
        $result = $this->evaluate($tenant);
        if ($result['pass']) {
            return;
        }

        throw ValidationException::withMessages([
            'authentication_policy' => [
                'SSO-only activation blocked by Enforcement Readiness Gate: '.implode('; ', $result['failures']),
            ],
        ]);
    }
}
