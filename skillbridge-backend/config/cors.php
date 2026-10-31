<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | The frontend runs on a different origin (Vite dev server, port 3000)
    | than the API (php artisan serve, port 8000). Browsers block cross-origin
    | fetch() calls unless the server explicitly allows them here. Postman
    | ignores CORS entirely, which is why Postman testing can look fine while
    | the browser still fails.
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => [
        'http://localhost:3000',
        'http://127.0.0.1:3000',
        // Vite also falls back to the next free port if 3000 is busy.
        'http://localhost:3001',
        'http://127.0.0.1:3001',
    ],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    // Using Sanctum Bearer tokens (not cookies), so credentials aren't needed.
    'supports_credentials' => false,

];
