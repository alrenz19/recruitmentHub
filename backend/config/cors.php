<?php


return [
    'paths' => ['api/*'],
    'allowed_methods' => ['*'],

    // Use regex pattern here
    'allowed_origins' => [
        'http://localhost:5173',
        'http://192.168.192.1:5173',
    ],
    'allowed_origins_patterns' => [
        '#^http://172\.16\.98\.\d+:5173$#', // matches 172.16.98.anything:5173
    ],

        'allowed_origins_patterns' => [
        '#^http://192\.168\.192\.\d+:5173$#', // matches 192.168.192.anything:5173
    ],
    

    'allowed_headers' => ['*'],
    'exposed_headers' => [],
    'max_age' => 0,
    'supports_credentials' => true,
];
