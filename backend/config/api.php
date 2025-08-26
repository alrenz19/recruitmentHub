<?php

return [
    'version' => env('API_VERSION', 'v1'),
    'rate_limits' => [
        'authenticated' => env('API_RATE_LIMIT_AUTH', 300),
        'unauthenticated' => env('API_RATE_LIMIT_UNAUTH', 100),
    ],
    'enable_fingerprinting' => env('API_ENABLE_FINGERPRINTING', true),
];