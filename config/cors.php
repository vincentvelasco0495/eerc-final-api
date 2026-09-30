<?php

return [

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => array_filter(explode(',', (string) env(
        'CORS_ALLOWED_ORIGINS',
        'http://localhost:3030,http://127.0.0.1:3030,http://localhost:3033,http://127.0.0.1:3033'
    ))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => ['Accept-Ranges', 'Content-Range', 'Content-Length', 'Content-Type'],

    'max_age' => 0,

    'supports_credentials' => true,

];
