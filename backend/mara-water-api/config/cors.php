<?php

/*
|--------------------------------------------------------------------------
| Cross-Origin Resource Sharing (CORS) Configuration
|--------------------------------------------------------------------------
|
| The React frontend (Vercel) calls this API from a different origin.
| Auth is a Bearer token in the Authorization header (no cookies), so we
| do not need credentialed CORS and can safely allow any origin.
|
| To lock this down later, set CORS_ALLOWED_ORIGINS to a comma-separated
| list of exact origins (e.g. "https://mara-water.vercel.app") and it will
| be used instead of "*".
|
*/

$allowedOrigins = array_values(array_filter(array_map(
    'trim',
    explode(',', (string) env('CORS_ALLOWED_ORIGINS', ''))
)));

return [

    'paths' => ['api/*', 'sanctum/csrf-cookie', 'up'],

    'allowed_methods' => ['*'],

    'allowed_origins' => $allowedOrigins ?: ['*'],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];
