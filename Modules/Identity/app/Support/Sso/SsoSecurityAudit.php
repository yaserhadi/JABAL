<?php

namespace Modules\Identity\Support\Sso;

use App\Support\Contracts\Audit\AuditLoggerInterface;

/**
 * BK-082 WS7: secret-free SSO security audit helper (D19/D31).
 */
final class SsoSecurityAudit
{
    /** @var list<string> */
    private const ALLOWED_KEYS = [
        'tenant_id',
        'correlation_id',
        'transaction_id',
        'handoff_id',
        'identity_link_id',
        'idp_configuration_version_id',
        'user_session_id',
        'reason',
        'status',
        'purpose',
        'addressing_profile',
        'sessions_revoked',
        'event_id',
        'actor_user_id',
    ];

    public function __construct(
        protected AuditLoggerInterface $auditLogger,
    ) {}

    /**
     * @param  array<string, scalar|null>  $context
     */
    public function record(string $event, array $context = []): void
    {
        $safe = [];
        foreach ($context as $key => $value) {
            if (! is_string($key) || ! in_array($key, self::ALLOWED_KEYS, true)) {
                continue;
            }
            if (is_scalar($value) || $value === null) {
                $safe[$key] = $value;
            }
        }

        $this->auditLogger->log($event, $safe);
    }
}
