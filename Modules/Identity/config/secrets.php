<?php

/**
 * BK-098: Provider-neutral secret runtime wiring (foundation).
 * local_sealed is not registered until a later authorized phase.
 */
return [
    /*
    |--------------------------------------------------------------------------
    | Runtime class allowlist (future local_sealed gate)
    |--------------------------------------------------------------------------
    | APP_ENV alone is insufficient. Missing/unknown deny when adapters register.
    */
    'runtime_class' => env('SECRET_RUNTIME_CLASS'),

    'allowed_runtime_classes_for_local_sealed' => [
        'local',
        'testing',
    ],

    /*
    |--------------------------------------------------------------------------
    | Registered provider keys (foundation: none)
    |--------------------------------------------------------------------------
    */
    'registered_providers' => [
        // 'local_sealed' => not authorized in this phase
    ],
];
