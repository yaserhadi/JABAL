<?php

return [
    'discovery_timeout' => (int) env('SSO_DISCOVERY_TIMEOUT', 10),
    'pkce_verifier_length' => 64,
    'default_scopes' => ['openid', 'profile', 'email'],
    'session_key_prefix' => 'sso.auth.',
    'state_ttl' => (int) env('SSO_STATE_TTL', 600),
    // BK-082 Host Authentication Transaction / Handoff (DEC-0024 D6/D7)
    'auth_transaction_ttl' => (int) env('SSO_AUTH_TRANSACTION_TTL', 600),
    'handoff_ttl' => (int) env('SSO_HANDOFF_TTL', 60),
    'auth_transaction_concurrency' => (int) env('SSO_AUTH_TRANSACTION_CONCURRENCY', 3),
    // BK-099 Workforce SSO enrollment
    'enrollment_invitation_ttl_days' => (int) env('SSO_ENROLLMENT_INVITATION_TTL_DAYS', 7),
    'enrollment_login_resume_ttl' => (int) env('SSO_ENROLLMENT_LOGIN_RESUME_TTL', 600),
    'enrollment_continuation_ttl' => (int) env('SSO_ENROLLMENT_CONTINUATION_TTL', 60),
    // WAVE-1 GAP-008: Valid Session ≠ Fresh Session (seconds). Activity does not extend this.
    'first_link_freshness_ttl' => (int) env('SSO_FIRST_LINK_FRESHNESS_TTL', 900),
    // BK-082 WS3 host-only browser binding cookie names (IH-3)
    'tenant_continuation_cookie' => 'jabal_sso_tenant_continuation',
    'auth_binding_cookie' => 'jabal_sso_auth_binding',
    // BK-082 WS4: Host Authorization Response mode (query default; form_post when provider profile requires it)
    'host_response_mode' => env('SSO_HOST_RESPONSE_MODE', 'query'),
    'mfa_continuation_ttl' => (int) env('SSO_MFA_CONTINUATION_TTL', 300),
    // BK-082 WS7 retention for transient SSO cleanup (D21)
    'transient_cleanup_batch' => (int) env('SSO_TRANSIENT_CLEANUP_BATCH', 500),
    // BK-082 WS7 corrective — D22 JWKS / logout-token algs
    'jwks_cache_ttl' => (int) env('SSO_JWKS_CACHE_TTL', 300),
    'jwks_http_timeout' => (int) env('SSO_JWKS_HTTP_TIMEOUT', 5),
    'default_logout_token_signing_algs' => ['RS256'],
];
