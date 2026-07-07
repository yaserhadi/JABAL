<?php

return [
    'discovery_timeout' => (int) env('SSO_DISCOVERY_TIMEOUT', 10),
    'pkce_verifier_length' => 64,
    'default_scopes' => ['openid', 'profile', 'email'],
    'session_key_prefix' => 'sso.auth.',
    'state_ttl' => (int) env('SSO_STATE_TTL', 600),
];
