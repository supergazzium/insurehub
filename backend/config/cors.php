<?php

declare(strict_types=1);

/*
| CORS configuration.
|
| Allowed origins are read from FRONTEND_URLS (comma-separated) — fall back to
| the common Vite dev origins. Localhost and 127.0.0.1 are distinct origins to
| the browser, so include both.
*/

$rawOrigins = env('FRONTEND_URLS', 'http://localhost:5173,http://127.0.0.1:5173');

$origins = array_values(array_filter(array_map('trim', explode(',', (string) $rawOrigins))));

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],

    'allowed_origins' => $origins,

    'allowed_origins_patterns' => [],

    'allowed_headers' => [
        'Accept',
        'Authorization',
        'Content-Type',
        'Origin',
        'X-Requested-With',
        'X-XSRF-TOKEN',
    ],

    'exposed_headers' => [],

    'max_age' => 600,

    // True so future SPA cookie/Sanctum mode works without re-config.
    // With Bearer-token auth this has no effect either way.
    'supports_credentials' => true,
];
