<?php

/**
 * BK-069 — Tenant Handle policy (canonical product term).
 * Storage column remains tenants.slug.
 */
return [

    'min_length' => 3,

    'max_length' => 63,

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
