<?php

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie', 'cms-videos/*'],

    'allowed_methods' => ['*'],

    'allowed_origins' => [
        'https://eerc-final.netlify.app',
        'http://localhost:3030',
        'http://127.0.0.1:3030',
    ],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [
        'Accept-Ranges',
        'Content-Range',
        'Content-Length',
        'Content-Type',
        'ETag',
        'Cache-Control',
    ],

    'max_age' => 86400,

    'supports_credentials' => false,
];