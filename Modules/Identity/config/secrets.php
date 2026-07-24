<?php

/**
 * BK-098: Provider-neutral secret runtime wiring + local_sealed adapter.
 * Production use of local_sealed remains prohibited under BK-098.
 *
 * SECRET_RUNTIME_CLASS is independent of Laravel APP_ENV labels.
 */
return [
    'runtime_class' => env('SECRET_RUNTIME_CLASS'),

    /*
    | Owner-locked classification values (literal):
    | local | development | test | controlled_uat | production
    */
    'known_runtime_classes' => [
        'local',
        'development',
        'test',
        'controlled_uat',
        'production',
    ],

    'environment_scopes_by_runtime_class' => [
        'local' => ['local'],
        'development' => ['development', 'local'],
        'test' => ['test', 'local'],
        'controlled_uat' => ['controlled_uat'],
        'production' => ['production'],
    ],

    /*
    | local_sealed allowlist — explicit non-production only.
    */
    'allowed_runtime_classes_for_local_sealed' => [
        'local',
        'development',
        'test',
        'controlled_uat',
    ],

    /*
    | Independent production-state guard (misconfiguration safety net).
    | When true, local_sealed denies even if SECRET_RUNTIME_CLASS is non-production.
    */
    'production_state_active' => (bool) (
        env('LOCAL_SEALED_FORCE_PRODUCTION_GUARD', false)
        || strtolower((string) env('APP_ENV', '')) === 'production'
        || strtolower((string) env('APP_ENV', '')) === 'prod'
    ),

    'registered_providers' => [
        // populated at boot when local_sealed.enabled and runtime allowlisted
    ],

    'local_sealed' => [
        'enabled' => (bool) env('LOCAL_SEALED_ENABLED', false),
        /*
        | Absolute directory outside public_path(). Physical seal files are derived
        | as: {store_path}/seals/{hh}/{sha256(provider|reference)}.seal
        */
        'store_path' => env('LOCAL_SEALED_STORE_PATH'),
        /*
        | External unsealing-key file path (32 raw bytes or base64 of 32 bytes).
        | Never place the key in the sealed payload, database, Git, or reports.
        */
        'unseal_key_file' => env('LOCAL_SEALED_UNSEAL_KEY_FILE'),
    ],
];
