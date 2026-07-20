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
    // BK-082 WS3 host-only browser binding cookie names (IH-3)
    'tenant_continuation_cookie' => 'jabal_sso_tenant_continuation',
    'auth_binding_cookie' => 'jabal_sso_auth_binding',
    // BK-082 WS4: Host Authorization Response mode (query default; form_post when provider profile requires it)
    'host_response_mode' => env('SSO_HOST_RESPONSE_MODE', 'query'),
];
