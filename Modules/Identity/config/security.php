<?php

return [
    'defaults' => [
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
    ],

    /**
     * WAVE-4 Authentication Administration freshness window (seconds).
     */
    'auth_admin_freshness_ttl' => (int) env('AUTH_ADMIN_FRESHNESS_TTL', 900),

    /**
     * Canonical email change mailbox-proof TTL (hours).
     */
    'email_change_ttl_hours' => (int) env('AUTH_ADMIN_EMAIL_CHANGE_TTL_HOURS', 24),
];
