<?php

namespace Modules\Identity\Services;

use App\Support\Contracts\Audit\AuditLoggerInterface;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Modules\Identity\Models\TenantSecurityPolicy;
use Modules\Identity\Support\SecurityFeatureGate;
use Modules\Tenancy\Models\Tenant;

/**
 * BK-043 / DEC-0011: Single access layer for tenant security policies.
 *
 * All security policy reads/writes go through this service.
 * Config defaults seed new rows only — no runtime inheritance.
 */
class SecurityPolicyService
{
    protected const ALLOWED_FIELDS = [
        'mfa_required',
        'mfa_grace_period_days',
        'password_policy',
        'session_idle_timeout',
        'authentication_policy',
        'mandatory_sso_enrollment',
        'sso_exception_closure_mode',
    ];

    public function __construct(
        protected SecurityFeatureGate $featureGate
    ) {}

    public function getForTenant(Tenant $tenant): array
    {
        return $this->withTenantContext($tenant, function () use ($tenant) {
            $record = $this->findOrCreateRow($tenant);

            return [
                'mfa_required' => $record->mfa_required,
                'mfa_grace_period_days' => $record->mfa_grace_period_days,
                'password_policy' => $record->password_policy,
                'session_idle_timeout' => $record->session_idle_timeout,
                'authentication_policy' => \Modules\Identity\Support\Auth\AuthenticationLoginPolicy::normalize(
                    is_string($record->authentication_policy) ? $record->authentication_policy : null
                ),
                'mandatory_sso_enrollment' => (bool) ($record->mandatory_sso_enrollment ?? false),
                'sso_exception_closure_mode' => in_array(
                    (string) ($record->sso_exception_closure_mode ?? 'automatic'),
                    ['automatic', 'manual'],
                    true
                ) ? (string) $record->sso_exception_closure_mode : 'automatic',
            ];
        });
    }

    public function update(Tenant $tenant, array $data, bool $bypassEnforcementGate = false): TenantSecurityPolicy
    {
        return $this->withTenantContext($tenant, function () use ($tenant, $data, $bypassEnforcementGate) {
            $payload = Arr::only($data, self::ALLOWED_FIELDS);

            if (array_key_exists('mfa_required', $payload) && $payload['mfa_required']) {
                if (! $this->featureGate->featureEnabled($tenant, 'mfa_available')) {
                    abort(403, 'MFA is not available for this tenant plan.');
                }
            }

            if (array_key_exists('authentication_policy', $payload)) {
                $payload['authentication_policy'] = \Modules\Identity\Support\Auth\AuthenticationLoginPolicy::normalize(
                    is_string($payload['authentication_policy']) ? $payload['authentication_policy'] : null
                );

                // WAVE-5: SSO-only activation requires Enforcement Readiness Gate PASS (fail closed).
                if ($payload['authentication_policy'] === \Modules\Identity\Support\Auth\AuthenticationLoginPolicy::SSO
                    && ! $bypassEnforcementGate
                ) {
                    $current = $this->getAuthenticationPolicy($tenant);
                    if ($current !== \Modules\Identity\Support\Auth\AuthenticationLoginPolicy::SSO) {
                        app(SsoEnforcementReadinessGate::class)->assertMayActivateSsoOnly($tenant);
                    }
                }
            }

            if (array_key_exists('sso_exception_closure_mode', $payload)) {
                $mode = strtolower(trim((string) $payload['sso_exception_closure_mode']));
                $payload['sso_exception_closure_mode'] = in_array($mode, ['automatic', 'manual'], true)
                    ? $mode
                    : 'automatic';
            }

            if (array_key_exists('mandatory_sso_enrollment', $payload)) {
                $payload['mandatory_sso_enrollment'] = (bool) $payload['mandatory_sso_enrollment'];
            }

            $existing = $this->findRow($tenant);
            $oldValues = $existing ? $existing->getAttributes() : [];

            if ($existing) {
                $existing->update($payload);
                $record = $existing;
            } else {
                $defaults = $this->configDefaults();
                $record = TenantSecurityPolicy::query()->create(array_merge([
                    'tenant_id' => $tenant->id,
                    'mfa_required' => $defaults['mfa_required'],
                    'mfa_grace_period_days' => $defaults['mfa_grace_period_days'],
                    'password_policy' => $defaults['password_policy'],
                    'session_idle_timeout' => $defaults['session_idle_timeout'],
                    'authentication_policy' => $defaults['authentication_policy'],
                    'mandatory_sso_enrollment' => $defaults['mandatory_sso_enrollment'] ?? false,
                    'sso_exception_closure_mode' => $defaults['sso_exception_closure_mode'] ?? 'automatic',
                ], $payload));
            }

            $record->refresh();

            $logger = app(AuditLoggerInterface::class);
            $base = [
                'tenant_id' => $tenant->getTenantKey(),
                'auditable_type' => TenantSecurityPolicy::class,
                'auditable_id' => (string) $record->getKey(),
                'old_values' => $existing ? Arr::only($oldValues, array_merge(['id', 'tenant_id'], self::ALLOWED_FIELDS)) : null,
                'new_values' => $record->only(self::ALLOWED_FIELDS),
                'changed_by' => Auth::id(),
            ];

            if (! $existing) {
                $logger->log('security_policy.created', $base);
            } else {
                $dirty = [];
                foreach (self::ALLOWED_FIELDS as $key) {
                    if (array_key_exists($key, $payload) && ($oldValues[$key] ?? null) != $record->getAttribute($key)) {
                        $dirty[] = $key;
                    }
                }
                if ($dirty !== []) {
                    $logger->log('security_policy.updated', $base);
                }
            }

            return $record;
        });
    }

    public function resetToDefaults(Tenant $tenant, ?array $fields = null): TenantSecurityPolicy
    {
        $defaults = $this->configDefaults();

        if ($fields !== null) {
            $resetPayload = Arr::only($defaults, array_intersect($fields, self::ALLOWED_FIELDS));
        } else {
            $resetPayload = Arr::only($defaults, self::ALLOWED_FIELDS);
        }

        return $this->update($tenant, $resetPayload);
    }

    public function isMfaRequired(Tenant $tenant): bool
    {
        return $this->withTenantContext($tenant, function () use ($tenant) {
            $record = $this->findRow($tenant);

            return (bool) ($record?->mfa_required ?? false);
        });
    }

    public function getPasswordPolicy(Tenant $tenant): array
    {
        return $this->withTenantContext($tenant, function () use ($tenant) {
            $record = $this->findOrCreateRow($tenant);

            return $record->password_policy;
        });
    }

    public function getSessionIdleTimeout(Tenant $tenant): int
    {
        return $this->withTenantContext($tenant, function () use ($tenant) {
            $record = $this->findOrCreateRow($tenant);

            return $record->session_idle_timeout;
        });
    }

    public function getMfaGracePeriodDays(Tenant $tenant): int
    {
        return $this->withTenantContext($tenant, function () use ($tenant) {
            $record = $this->findOrCreateRow($tenant);

            return $record->mfa_grace_period_days;
        });
    }

    /**
     * WAVE-3 GAP-009: Password | SSO | Both (LOGIN permission, not credential readiness).
     */
    public function getAuthenticationPolicy(Tenant $tenant): string
    {
        return $this->withTenantContext($tenant, function () use ($tenant) {
            $record = $this->findOrCreateRow($tenant);

            return \Modules\Identity\Support\Auth\AuthenticationLoginPolicy::normalize(
                is_string($record->authentication_policy) ? $record->authentication_policy : null
            );
        });
    }

    public function isMandatorySsoEnrollmentActive(Tenant $tenant): bool
    {
        return $this->withTenantContext($tenant, function () use ($tenant) {
            $record = $this->findOrCreateRow($tenant);

            return (bool) ($record->mandatory_sso_enrollment ?? false);
        });
    }

    public function getSsoExceptionClosureMode(Tenant $tenant): string
    {
        return $this->withTenantContext($tenant, function () use ($tenant) {
            $record = $this->findOrCreateRow($tenant);
            $mode = strtolower(trim((string) ($record->sso_exception_closure_mode ?? 'automatic')));

            return in_array($mode, ['automatic', 'manual'], true) ? $mode : 'automatic';
        });
    }

    protected function findRow(Tenant $tenant): ?TenantSecurityPolicy
    {
        return TenantSecurityPolicy::query()->where('tenant_id', $tenant->id)->first();
    }

    protected function findOrCreateRow(Tenant $tenant): TenantSecurityPolicy
    {
        $record = $this->findRow($tenant);

        if ($record) {
            return $record;
        }

        $defaults = $this->configDefaults();

        return TenantSecurityPolicy::query()->create([
            'tenant_id' => $tenant->id,
            'mfa_required' => $defaults['mfa_required'],
            'mfa_grace_period_days' => $defaults['mfa_grace_period_days'],
            'password_policy' => $defaults['password_policy'],
            'session_idle_timeout' => $defaults['session_idle_timeout'],
            'authentication_policy' => $defaults['authentication_policy'] ?? 'both',
            'mandatory_sso_enrollment' => $defaults['mandatory_sso_enrollment'] ?? false,
            'sso_exception_closure_mode' => $defaults['sso_exception_closure_mode'] ?? 'automatic',
        ]);
    }

    protected function configDefaults(): array
    {
        return config('identity.security.defaults', [
            'mfa_required' => false,
            'mfa_grace_period_days' => 0,
            'password_policy' => [
                'min_length' => 8,
                'require_uppercase' => false,
                'require_number' => false,
                'require_special' => false,
            ],
            'session_idle_timeout' => -1,
            'authentication_policy' => 'both',
            'mandatory_sso_enrollment' => false,
            'sso_exception_closure_mode' => 'automatic',
        ]);
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    protected function withTenantContext(Tenant $tenant, callable $callback)
    {
        $wasInitialized = tenancy()->initialized;
        $previousTenant = $wasInitialized ? tenancy()->tenant : null;

        try {
            if (! $wasInitialized || tenancy()->tenant?->id !== $tenant->id) {
                tenancy()->initialize($tenant);
            }

            return $callback();
        } finally {
            if (! $wasInitialized) {
                tenancy()->end();
            } elseif ($previousTenant && $previousTenant->id !== $tenant->id) {
                tenancy()->initialize($previousTenant);
            }
        }
    }
}
