<?php

/**
 * BK-098: Provider-neutral secret runtime wiring (foundation).
 * local_sealed is not registered until a later authorized phase.
 */
return [
    /*
    |--------------------------------------------------------------------------
    | Explicit runtime security classification (not APP_ENV alone)
    |--------------------------------------------------------------------------
    */
    'runtime_class' => env('SECRET_RUNTIME_CLASS'),

    'known_runtime_classes' => [
        'local',
        'testing',
        'staging',
        'production',
    ],

    /*
    |--------------------------------------------------------------------------
    | Environment scopes allowed per SECRET_RUNTIME_CLASS
    |--------------------------------------------------------------------------
    | Credential rows must carry credential_environment_scope matching this map.
    */
    'environment_scopes_by_runtime_class' => [
        'local' => ['local'],
        'testing' => ['testing', 'local'],
        'staging' => ['staging'],
        'production' => ['production'],
    ],

    /*
    |--------------------------------------------------------------------------
    | local_sealed allowlist (adapter phase — not registered yet)
    |--------------------------------------------------------------------------
    */
    'allowed_runtime_classes_for_local_sealed' => [
        'local',
        'testing',
    ],

    'registered_providers' => [
        // 'local_sealed' => not authorized in this phase
    ],
];
