<?php

/**
 * BK-069 / BK-125 Wave 7 — Tenant Handle policy (canonical product term).
 * Storage column remains tenants.slug; lifecycle in tenant_handle_allocations.
 */
return [

    'min_length' => 3,

    'max_length' => 63,

    /*
    | Minimum quarantine (days) after retirement before a handle may become RELEASABLE.
    | Time alone never releases — dependency-clear checks are also required.
    */
    'quarantine_days' => max(0, (int) env('TENANT_HANDLE_QUARANTINE_DAYS', 30)),

    /*
    | Exact reserved handles (lowercase).
    */
    'reserved' => [
        'www',
        'api',
        'admin',
        'platform',
        'app',
        'auth',
        'login',
        'logout',
        'billing',
        'security',
        'support',
        'help',
        'mail',
        'static',
        'assets',
        'cdn',
        'status',
        'test',
        'local',
        'localhost',
    ],

    /*
    | Prefix patterns (handle starts with …). Checked after normalize.
    */
    'reserved_prefixes' => [
        'api-',
        'www-',
    ],
];
