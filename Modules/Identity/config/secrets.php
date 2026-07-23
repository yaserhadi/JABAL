<?php

/**
 * BK-098: Provider-neutral secret runtime wiring + local_sealed adapter.
 * Production use of local_sealed remains prohibited under BK-098.
 */
return [
    'runtime_class' => env('SECRET_RUNTIME_CLASS'),

    'known_runtime_classes' => [
        'local',
        'testing',
        'staging',
        'production',
    ],

    'environment_scopes_by_runtime_class' => [
        'local' => ['local'],
        'testing' => ['testing', 'local'],
        'staging' => ['staging'],
        'production' => ['production'],
    ],

    'allowed_runtime_classes_for_local_sealed' => [
        'local',
        'testing',
    ],

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
