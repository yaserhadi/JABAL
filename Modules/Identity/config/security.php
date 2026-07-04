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
    ],
];
