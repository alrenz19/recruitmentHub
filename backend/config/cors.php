<?php


return [
    'paths' => ['api/*'],
    'allowed_methods' => ['*'],

    // Use regex pattern here
    'allowed_origins' => [
        'http://localhost:5173',
        'http://192.168.192.1:5173',
        'http://172.16.98.100:5173',
    ],
    'allowed_origins_patterns' => [
        '#^http://192\.168\.192\.\d+:5173$#', // matches 192.168.192.anything:5173
        '#^http://172\.16\.98\.\d+:5173$#', // matches 172.16.98.anything:5173
        '#^http://172\.(1[6-9]|2[0-9]|3[0-1])\.\d+\.\d+:5173$#', // 172.16.0.0 – 172.31.255.255:5173
    ],
    'allowed_headers' => ['*'],
    'exposed_headers' => [],
    'max_age' => 0,
    'supports_credentials' => true,
];
