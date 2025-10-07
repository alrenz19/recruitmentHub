<?php
return [
    'paths' => ['api/*', 'broadcasting/*'],
    'allowed_methods' => ['*'],
    'allowed_origins_patterns' => [
        '#^http://(localhost|127\.0\.0\.1):5173$#',
        '#^http://172\.(1[6-9]|2[0-9]|3[0-1])\.\d+\.\d+:(5173|5174)$#',
        '#^http://192\.168\.\d+\.\d+:(5173|5174)$#',
    ],
    'allowed_headers' => ['*'],
    'supports_credentials' => true,
    'max_age' => 86400,
];
